<?php

namespace App\Services\DocumentReading;

use App\Models\AuditLog;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\User;
use App\Services\DocumentReading\Contracts\ReadKind;
use App\Services\Notify\NotificationMessage;
use App\Services\Notify\Notifier;
use finfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Takes in what a read is made of: its page pictures and, for the record, the original PDF.
 *
 * Nothing is kept as it arrives. A picture is redrawn by the server first (PageImage), so what is
 * stored is upright, carries no camera or location details and is always a JPEG. A file that cannot
 * be redrawn is refused and nothing of it is stored.
 *
 * A page's file name is fixed by its number, pass and part, so sending the same page again replaces
 * the earlier picture. The row is written before the file, and loses its path again if the file
 * cannot be kept, so no row ever points at a file that is not there.
 */
class ReadIntake
{
    public const FULL = 'full';

    public const TOP = 'top';

    public const BOTTOM = 'bottom';

    public const PARTS = [self::FULL, self::TOP, self::BOTTOM];

    /** Page pictures can be added until the read has started. */
    private const BEFORE_START = [DocumentRead::DRAFT, DocumentRead::PREPARED, DocumentRead::QUOTED];

    /** The two halves of a cut-off page can be added once the read has run. */
    public const AFTER_START = [DocumentRead::RUNNING, DocumentRead::PAUSED, DocumentRead::READY, DocumentRead::FAILED];

    private const LARGEST_PAGE_NUMBER = 100000;

    public function __construct(private Notifier $notifier) {}

    /** The pass a page picture of this kind is read with, or null when the kind takes no pictures. */
    public static function picturePass(string $kind): ?string
    {
        $pass = config("modules.document_reading.kinds.{$kind}.picture_pass");

        return is_string($pass) && is_array(config("modules.document_reading.kinds.{$kind}.passes.{$pass}")) ? $pass : null;
    }

    /**
     * The passes of this kind that read a page picture: the pass an uploaded picture is read with, and
     * every pass that follows it on the same page ("then" in the kind's figures), which the page reader
     * gives the same picture. Every other pass of the kind reads the text of its pages.
     *
     * @return list<string>
     */
    public static function picturePasses(string $kind): array
    {
        $first = self::picturePass($kind);
        $passes = $first === null ? [] : [$first];

        for ($at = 0; $at < count($passes); $at++) {
            foreach ((array) config("modules.document_reading.kinds.{$kind}.passes.{$passes[$at]}.then", []) as $next) {
                if (is_string($next) && ! in_array($next, $passes, true) && is_array(config("modules.document_reading.kinds.{$kind}.passes.{$next}"))) {
                    $passes[] = $next;
                }
            }
        }

        return $passes;
    }

    /**
     * Where a half of a page lies on the whole page, in parts per thousand from the top.
     *
     * @return array{top:int, height:int}
     */
    public static function halfOf(string $part): array
    {
        $share = min(1000, max(500, (int) config('modules.document_reading.half_page_share', 550)));

        return ['top' => $part === self::BOTTOM ? 1000 - $share : 0, 'height' => $share];
    }

    /**
     * Stores one page picture, or one half of a page whose answer was cut off.
     *
     * A whole page is taken until the read starts; sending the same page number again replaces the
     * stored picture. A half is taken only for a page that failed as cut off and whose kind offers
     * halves, and it waits, unsent, until both halves are there and someone asks for them to be read.
     *
     * @param  string  $bytes  The upload exactly as it arrived.
     *
     * @throws ImageRefused The picture cannot be used. Nothing was stored.
     * @throws ReaderUnavailable The server cannot redraw pictures. Nothing was stored, and the platform admins are told.
     * @throws ReadRefused wrong_state, too_many_pages or not_stored.
     * @throws ValidationException A page number the read does not have.
     */
    public function storePage(DocumentRead $read, ReadKind $kind, int $pageNo, string $part, string $bytes): DocumentReadPage
    {
        $pass = self::picturePass($read->kind) ?? throw ReadRefused::wrongState($read);

        if ($pageNo < 1 || $pageNo > self::LARGEST_PAGE_NUMBER || ((int) $read->pages_total > 0 && $pageNo > (int) $read->pages_total)) {
            throw ValidationException::withMessages(['page_no' => [__('This document has no page :number.', ['number' => $pageNo])]]);
        }

        // A first look, so a picture that cannot be taken anyway is not redrawn. The same is checked again under the lock.
        if ($read->files_purged_at !== null || ! in_array($read->status, $part === self::FULL ? self::BEFORE_START : self::AFTER_START, true)) {
            throw ReadRefused::wrongState($read);
        }

        $limits = $kind->limits();

        try {
            $image = app(PageImage::class)::normalise($bytes, (int) $limits['min_short_side']);
        } catch (ReaderUnavailable $unavailable) {
            $this->tellAdmins($unavailable);

            throw $unavailable;
        }

        $stored = DB::transaction(function () use ($read, $kind, $pass, $pageNo, $part, $image, $limits) {
            // The read is locked while its page is written, so a start cannot slip in between the row and the file.
            $locked = $this->locked($read);
            $rows = fn () => $this->pages($locked)->where('page_no', $pageNo)->where('pass', $pass);
            $row = $rows()->where('part', $part)->lockForUpdate()->first();

            if ($locked->files_purged_at !== null) {
                throw ReadRefused::wrongState($locked);
            }

            if ($part === self::FULL) {
                $this->assertWholePageFits($locked, $row, $pass, (int) $limits['max_pages']);
            } else {
                $this->assertHalfIsWanted($locked, $kind, $rows()->where('part', self::FULL)->first(), $row);
            }

            $half = $part === self::FULL ? ['top' => null, 'height' => null] : self::halfOf($part);
            $path = ReadFiles::page($locked, $pageNo, $pass, $part);

            $values = [
                // A half waits, set aside, until both are there and someone asks for them to be read.
                'status' => $part === self::FULL ? DocumentReadPage::WAITING : DocumentReadPage::SKIPPED,
                'model' => (string) ReaderRequest::figures($locked->kind, $pass)['model'],
                'image_path' => $path,
                'image_sha256' => $image->sha256,
                'image_width' => $image->width,
                'image_height' => $image->height,
                'image_bytes' => strlen($image->bytes),
                'part_top' => $half['top'],
                'part_height' => $half['height'],
                'page_to' => null,
                'text_chars' => null,
                'needs_picture' => false,
            ];

            $row === null
                ? $row = DocumentReadPage::withoutGlobalScopes()->create(['document_read_id' => $locked->id, 'page_no' => $pageNo, 'pass' => $pass, 'part' => $part, ...$values])
                : $row->forceFill($values)->save();

            if (! $this->put($path, $image->bytes)) {
                $row->forceFill(['image_path' => null, 'image_sha256' => null, 'image_width' => null, 'image_height' => null, 'image_bytes' => null])->save();

                return null;
            }

            // An upload is a sign of life: a draft that is still being filled is not cleared away.
            $locked->touch();

            // Who put which picture in, by its fingerprint. Never what is on it.
            AuditLog::record('ai.page_stored', $row, null, [
                'read_id' => $locked->id,
                'kind' => ReadAudit::code($locked->kind),
                'page_no' => $pageNo,
                'pass' => ReadAudit::code($pass),
                'part' => ReadAudit::code($part),
                'bytes' => strlen($image->bytes),
                'sha256' => $image->sha256,
                'width' => $image->width,
                'height' => $image->height,
            ], null, (int) $locked->organization_id);

            return $row;
        });

        if ($stored === null) {
            throw new ReadRefused(ReadRefused::NOT_STORED, __('The page could not be stored. Send it again.'));
        }

        return $stored;
    }

    /**
     * Keeps the original PDF beside the page pictures, for the record. It is never opened, parsed or
     * sent anywhere: what is read are the page pictures. Only kinds marked "keeps_source" take one.
     *
     * @param  string  $bytes  The upload exactly as it arrived.
     * @param  ?string  $name  The file's name on the person's computer, kept only when the read has none yet.
     *
     * @throws ReadRefused wrong_state, not_a_pdf or not_stored.
     */
    public function storeSource(DocumentRead $read, string $bytes, ?string $name = null): void
    {
        $largest = 1024 * (int) config('modules.document_reading.upload.source_kb', 10240);
        $type = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if ($type !== 'application/pdf' || ! str_starts_with($bytes, '%PDF-') || strlen($bytes) > $largest) {
            throw new ReadRefused(ReadRefused::NOT_A_PDF, __('Only a PDF file of up to :mb MB can be kept as the original.', ['mb' => intdiv($largest, 1024 * 1024)]), ['max_bytes' => $largest], 422);
        }

        $kept = DB::transaction(function () use ($read, $bytes, $name) {
            $locked = $this->locked($read);

            if ($locked->files_purged_at !== null || $locked->isClosed() || $locked->status === DocumentRead::FAILED) {
                throw ReadRefused::wrongState($locked);
            }

            $path = ReadFiles::source($locked);

            $locked->forceFill([
                'source_path' => $path,
                'source_sha256' => hash('sha256', $bytes),
                'source_bytes' => strlen($bytes),
                'source_name' => filled($locked->source_name) ? $locked->source_name : (blank($name) ? null : Str::limit(basename((string) $name), 150, '')),
            ])->save();

            if (! $this->put($path, $bytes)) {
                $locked->forceFill(['source_path' => null, 'source_sha256' => null, 'source_bytes' => 0])->save();

                return false;
            }

            AuditLog::record('ai.source_stored', $locked, null, [
                'kind' => ReadAudit::code($locked->kind),
                'bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
            ], null, (int) $locked->organization_id);

            return true;
        });

        if (! $kept) {
            throw new ReadRefused(ReadRefused::NOT_STORED, __('The file could not be stored. Send it again.'));
        }
    }

    /** A whole page is taken until the read starts, and no more of them than the kind reads. */
    private function assertWholePageFits(DocumentRead $read, ?DocumentReadPage $row, string $pass, int $mostPages): void
    {
        if (! in_array($read->status, self::BEFORE_START, true)) {
            throw ReadRefused::wrongState($read, $row?->status);
        }

        if ($row !== null && $row->status !== DocumentReadPage::WAITING) {
            throw ReadRefused::wrongState($read, $row->status);
        }

        $others = $this->pages($read)->where('pass', $pass)->where('part', self::FULL)->whereNotNull('image_path')
            ->when($row !== null, fn ($query) => $query->whereKeyNot($row->id))
            ->count();

        if ($others >= $mostPages) {
            throw new ReadRefused(ReadRefused::TOO_MANY_PAGES, __('This document can have :pages pages at most.', ['pages' => $mostPages]), ['max_pages' => $mostPages], 422);
        }
    }

    /** A half is taken only for a page whose answer was cut off and whose kind offers halves, and only until the halves are sent. */
    private function assertHalfIsWanted(DocumentRead $read, ReadKind $kind, ?DocumentReadPage $whole, ?DocumentReadPage $half): void
    {
        $offered = in_array($read->status, self::AFTER_START, true)
            && $whole !== null
            && self::canBeSplit($whole->setRelation('documentRead', $read), $kind);

        if (! $offered || ($half !== null && $half->status !== DocumentReadPage::SKIPPED)) {
            throw ReadRefused::wrongState($read, ($half ?? $whole)?->status);
        }
    }

    /** Whether this page row is a whole page that failed because its answer was cut off, of a kind that then offers halves. */
    public static function canBeSplit(DocumentReadPage $page, ReadKind $kind): bool
    {
        return $page->part === self::FULL
            && $page->status === DocumentReadPage::FAILED
            && $page->fail_reason === PageOutcome::CUT_OFF
            && filled($page->image_path)
            && $kind->onCutOff($page) === 'offer_halves';
    }

    private function put(string $path, string $bytes): bool
    {
        try {
            return Storage::put($path, $bytes) === true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function locked(DocumentRead $read): DocumentRead
    {
        return DocumentRead::withoutGlobalScopes()->where('organization_id', $read->organization_id)->lockForUpdate()->findOrFail($read->id);
    }

    private function pages(DocumentRead $read)
    {
        return DocumentReadPage::withoutGlobalScopes()
            ->where('organization_id', $read->organization_id)
            ->where('document_read_id', $read->id);
    }

    /** The platform admins are told once a day that uploads are being refused because the server cannot redraw pictures. */
    private function tellAdmins(ReaderUnavailable $unavailable): void
    {
        if (! Cache::add('ai.image_tools_notice:'.now('Asia/Kolkata')->toDateString(), 1, now()->addDay())) {
            return;
        }

        $reason = $unavailable->reason;

        $admins = User::where('is_active', true)
            ->where(fn ($query) => $query->where('is_platform_admin', true)->orWhereIn('email', config('buildos.platform_admins')))
            ->orderBy('id')->get()->filter->isPlatformAdmin();

        foreach ($admins as $admin) {
            $this->notifier->send($admin, fn (string $locale) => new NotificationMessage(
                event: 'ai.image_tools',
                title: __('The AI readers cannot take page pictures on this server', [], $locale),
                body: $reason === ReaderUnavailable::IMAGE_TOOLS_MISSING
                    ? __('The image functions of PHP (GD, with JPEG, PNG and WebP) are missing, so every uploaded page is refused. Nothing was stored.', [], $locale)
                    : __('The image functions of PHP failed while a page was being redrawn, so the upload was refused. Nothing was stored.', [], $locale),
                url: Route::has('ai-reader.admin.index') ? route('ai-reader.admin.index') : null,
                critical: true,
            ));
        }
    }
}
