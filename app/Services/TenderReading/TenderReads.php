<?php

namespace App\Services\TenderReading;

use App\Jobs\TenderReading\PrepareTenderText;
use App\Models\AuditLog;
use App\Models\CrmTender;
use App\Models\CrmTenderDocument;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\User;
use App\Services\DocumentReading\ReadDesk;
use App\Services\DocumentReading\ReaderRequest;
use App\Services\DocumentReading\ReadQueues;
use App\Services\DocumentReading\ReadRefused;
use App\Support\ClientUuidRepeat;
use finfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Starting a read of a tender document and answering the questions before it runs: the document's
 * language and whether the text taken out of it matches its pages. The price, the start and the
 * progress go through the shared reader routes like every other read.
 *
 * A read has one of three sources. A PDF of up to the size limit is read through its text, with any
 * page whose text cannot be trusted read as a page picture ("text"). A JPG or PNG document is one
 * page picture ("image"). A PDF over the size limit, or one the person chose to read that way after
 * its text could not be taken out, is read as page pictures in a range of pages ("pictures"). Page
 * pictures are drawn by the browser and uploaded through the shared upload route; the server
 * redraws each one before it is stored.
 */
class TenderReads
{
    /** Another prepare of the company is still going, or it has started as many as it may this hour. */
    public const BUSY = 'busy';

    /** The file is not a PDF the text can be taken out of. */
    public const NOT_READABLE = 'not_readable';

    public const LANGUAGES = ['en', 'mr', 'hi'];

    /** A PDF read through its text layer; pages whose text cannot be trusted are read as pictures. */
    public const SOURCE_TEXT = 'text';

    /** A JPG or PNG document: one page picture. */
    public const SOURCE_IMAGE = 'image';

    /** A PDF read only as page pictures, a range of pages at a time. */
    public const SOURCE_PICTURES = 'pictures';

    /** Asked for on the create route: read the PDF as page pictures. */
    public const AS_PICTURES = 'pictures';

    public function __construct(private ReadDesk $desk, private ReadQueues $queues) {}

    /**
     * Opens a read of one of the tender's documents. A PDF within the size limit has its text taken
     * out on the queue; a picture, a larger PDF, or a PDF asked for as pictures is ready at once for
     * the questions, and its pages are uploaded as pictures. A repeated client_uuid gives back the
     * read it made the first time.
     *
     * @param  ?string  $as  AS_PICTURES to read a PDF only as page pictures.
     *
     * @throws ReadRefused not_readable (422) or busy (409)
     * @throws ValidationException a client_uuid that stands for a read of another document
     */
    public function open(CrmTender $tender, CrmTenderDocument $document, User $by, ?string $clientUuid, ?string $as = null): DocumentRead
    {
        $earlier = $clientUuid === null ? null : ClientUuidRepeat::reachable(
            DocumentRead::withoutGlobalScopes()->where('organization_id', $by->organization_id)->where('client_uuid', $clientUuid)->first()
        );

        if ($earlier instanceof DocumentRead) {
            if ($earlier->kind === TenderReadKind::KEY && (int) ($earlier->options['document_id'] ?? 0) === (int) $document->id) {
                return $earlier;
            }

            // The key was used before for something else: it does not stand for a read of this document.
            throw ValidationException::withMessages(['client_uuid' => [__('Record not found on the server.')]]);
        }

        [$type, $size] = $this->inspect($document);
        $tooLarge = $type === 'pdf' && $size > TenderFields::setting('max_bytes', 10 * 1024 * 1024);
        $source = match (true) {
            $type === 'image' => self::SOURCE_IMAGE,
            $tooLarge, $as === self::AS_PICTURES => self::SOURCE_PICTURES,
            default => self::SOURCE_TEXT,
        };

        $lock = Cache::lock('tender-prepare:'.$by->organization_id, 10);

        try {
            $lock->block(5);
        } catch (Throwable) {
            throw $this->busy();
        }

        try {
            $this->assertNotBusy((int) $by->organization_id);

            $read = $this->desk->open($by, TenderReadKind::KEY, [
                'client_uuid' => $clientUuid,
                'source_name' => $document->original_name ?: $document->title,
                'subject' => $document,
                'pages_total' => $source === self::SOURCE_IMAGE ? 1 : 0,
                'options' => ['tender_id' => (int) $tender->id, 'document_id' => (int) $document->id, 'source' => $source, ...($tooLarge ? ['too_large' => true] : [])],
            ]);
        } finally {
            $lock->release();
        }

        // Two requests with one key for two documents at the same moment: the second does not get the first one's read.
        if ((int) ($read->options['document_id'] ?? 0) !== (int) $document->id) {
            throw ValidationException::withMessages(['client_uuid' => [__('Record not found on the server.')]]);
        }

        if (! $read->wasRecentlyCreated) {
            return $read;
        }

        if (self::source($read) !== self::SOURCE_TEXT) {
            // Nothing to take out: the pages arrive as pictures. A picture read has no text, no flags and no runs.
            DocumentRead::withoutGlobalScopes()->where('organization_id', $read->organization_id)->whereKey($read->id)
                ->where('status', DocumentRead::DRAFT)
                ->update(['status' => DocumentRead::PREPARED, 'updated_at' => now(), 'options' => json_encode([
                    ...(array) $read->options,
                    'page_flags' => [],
                    'letters' => 0,
                    'devanagari' => 0,
                    'samples' => [],
                    'runs' => [],
                ])]);

            $created = $read->wasRecentlyCreated;
            $read = $read->refresh();
            $read->wasRecentlyCreated = $created;

            return $read;
        }

        $job = PrepareTenderText::dispatch($read->id)->afterCommit();

        if (($queue = $this->queues->name(ReadQueues::PREPARE)) !== null) {
            $job->onQueue($queue);
        }

        return $read;
    }

    /**
     * The person's answers before the read is priced: the language of the document, and whether the
     * text on the left matches the page on the right. Pages whose text cannot be trusted are set aside
     * to be read as page pictures; the rest wait to be read as text. Can be answered again until the
     * read starts.
     *
     * A read of pictures only is answered with its language and, for a PDF, the range of pages to
     * read; $matches is false for it, since there is no text to compare.
     *
     * Page pictures already uploaded follow the answers: a page that is to be read as a picture waits
     * to be read, any other is set aside, so a page is never read both as text and as a picture.
     *
     * @param  array{page_from?: ?int, page_to?: ?int}  $range  The pages the person wants read, when not all of them.
     * @return list<int> The pages that can only be read as page pictures.
     *
     * @throws ReadRefused wrong_state
     */
    public function answer(DocumentRead $read, User $by, string $language, bool $matches, array $range = []): array
    {
        return DB::transaction(function () use ($read, $by, $language, $matches, $range) {
            $locked = DocumentRead::withoutGlobalScopes()->where('organization_id', $read->organization_id)->lockForUpdate()->findOrFail($read->id);

            if (! in_array($locked->status, [DocumentRead::PREPARED, DocumentRead::QUOTED], true) || $locked->files_purged_at !== null) {
                throw ReadRefused::wrongState($locked);
            }

            $range = array_filter(['page_from' => $range['page_from'] ?? null, 'page_to' => $range['page_to'] ?? null]);
            $pictures = self::picturePages($locked, $language, $matches, $range);

            // The runs are laid out again from the prepared list. A run with a page that needs a picture is
            // not kept as a row at all, so no later step can send its text after all.
            DocumentReadPage::withoutGlobalScopes()->where('organization_id', $locked->organization_id)
                ->where('document_read_id', $locked->id)->where('pass', TenderReadKind::TEXT)
                ->whereIn('status', [DocumentReadPage::WAITING, DocumentReadPage::SKIPPED])->delete();

            $model = (string) ReaderRequest::figures(TenderReadKind::KEY, TenderReadKind::TEXT)['model'];

            foreach ((array) ($locked->options['runs'] ?? []) as [$from, $to, $chars, $tokens]) {
                if (array_intersect(range((int) $from, (int) $to), $pictures) === []) {
                    DocumentReadPage::withoutGlobalScopes()->create([
                        'document_read_id' => $locked->id,
                        'page_no' => (int) $from,
                        'page_to' => (int) $to,
                        'pass' => TenderReadKind::TEXT,
                        'part' => 'full',
                        'status' => DocumentReadPage::WAITING,
                        'model' => $model,
                        'text_chars' => (int) $chars,
                        'input_tokens' => (int) $tokens,
                    ]);
                }
            }

            $images = DocumentReadPage::withoutGlobalScopes()->where('organization_id', $locked->organization_id)
                ->where('document_read_id', $locked->id)->where('pass', TenderReadKind::IMAGE)->where('part', 'full')
                ->whereIn('status', [DocumentReadPage::WAITING, DocumentReadPage::SKIPPED]);
            (clone $images)->whereIn('page_no', $pictures ?: [0])->update(['status' => DocumentReadPage::WAITING]);
            (clone $images)->whereNotIn('page_no', $pictures ?: [0])->update(['status' => DocumentReadPage::SKIPPED]);

            $locked->forceFill(['options' => [
                ...(array) $locked->options,
                'language' => $language,
                'matches' => $matches,
                'needs_picture' => $pictures,
                'range' => $range,
                'answered_by' => $by->id,
                'answered_at' => now()->toIso8601String(),
            ]])->save();

            AuditLog::record('tender_read.answered', $locked, null, [
                'source' => self::source($locked),
                'language' => $language,
                'matches' => $matches,
                'pages' => (int) $locked->pages_total,
                'picture_pages' => count($pictures),
            ], $by, (int) $locked->organization_id);

            return $pictures;
        });
    }

    /**
     * The pages that can only be read as pictures: those whose text a scanner laid over the page or an
     * old font draws, those without text, and every page when the person says the text does not match
     * or names Marathi or Hindi for a text with too few Devanagari letters. For a picture document it
     * is its one page; for a PDF read as pictures, the range the person chose.
     *
     * @param  ?array{page_from?: ?int, page_to?: ?int}  $range  For a PDF read as pictures: the range being answered, instead of the stored one.
     * @return list<int>
     */
    public static function picturePages(DocumentRead $read, ?string $language = null, ?bool $matches = null, ?array $range = null): array
    {
        $options = (array) $read->options;

        if (self::source($read) === self::SOURCE_IMAGE) {
            return [1];
        }

        if (self::source($read) === self::SOURCE_PICTURES) {
            $range ??= (array) ($options['range'] ?? []);
            $from = (int) ($range['page_from'] ?? 0);
            $to = (int) ($range['page_to'] ?? 0);

            return $from >= 1 && $to >= $from ? range($from, $to) : [];
        }

        $language ??= $options['language'] ?? null;
        $matches ??= $options['matches'] ?? null;
        $total = (int) $read->pages_total;
        $all = $total > 0 ? range(1, $total) : [];

        $letters = (int) ($options['letters'] ?? 0);
        $devanagari = (int) ($options['devanagari'] ?? 0);
        $tooFewDevanagari = in_array($language, ['mr', 'hi'], true)
            && $devanagari * 100 < $letters * TenderFields::setting('devanagari_share', 30);

        if ($matches === false || $tooFewDevanagari || ($letters === 0 && $total > 0)) {
            return $all;
        }

        $flagged = array_map(intval(...), array_keys((array) ($options['page_flags'] ?? [])));
        sort($flagged);

        return array_values(array_intersect($all, $flagged));
    }

    /** Whether the questions before the price have been answered. */
    public static function answered(DocumentRead $read): bool
    {
        return isset($read->options['language'], $read->options['matches']) && in_array($read->options['language'], array_keys(ReaderRequest::LANGUAGES), true);
    }

    /** Where the read takes its pages from: SOURCE_TEXT, SOURCE_IMAGE or SOURCE_PICTURES. A read from before pictures were offered is a text read. */
    public static function source(DocumentRead $read): string
    {
        $source = $read->options['source'] ?? null;

        return in_array($source, [self::SOURCE_IMAGE, self::SOURCE_PICTURES], true) ? $source : self::SOURCE_TEXT;
    }

    /**
     * The page pictures the screen offers to draw and upload: the pages that can only be read as
     * pictures, within the range the person chose.
     *
     * @return list<int>
     */
    public static function picturesToRead(DocumentRead $read): array
    {
        if (! self::answered($read)) {
            return [];
        }

        $range = (array) ($read->options['range'] ?? []);
        $from = isset($range['page_from']) ? (int) $range['page_from'] : null;
        $to = isset($range['page_to']) ? (int) $range['page_to'] : null;

        return array_values(array_filter(self::picturePages($read), fn (int $page) => ($from === null || $page >= $from) && ($to === null || $page <= $to)));
    }

    /** @return list<int> The pages of a read whose whole-page picture is stored. */
    public static function storedPictures(DocumentRead $read): array
    {
        return DocumentReadPage::withoutGlobalScopes()->where('organization_id', $read->organization_id)
            ->where('document_read_id', $read->id)->where('pass', TenderReadKind::IMAGE)->where('part', 'full')
            ->whereNotNull('image_path')->orderBy('page_no')->pluck('page_no')->map(fn ($page) => (int) $page)->uniqueStrict()->values()->all();
    }

    /**
     * What the stored file is, by its content: a PDF, or a JPG or PNG picture. The upload checked the
     * name only, so a file is judged by its first bytes here; a PDF is checked in full again when its
     * text is taken out.
     *
     * @return array{0: 'pdf'|'image', 1: int} The type and the size in bytes.
     *
     * @throws ReadRefused
     */
    private function inspect(CrmTenderDocument $document): array
    {
        try {
            $size = Storage::exists($document->path) ? (int) Storage::size($document->path) : 0;
            $head = $size > 0 ? $this->head($document->path) : '';
        } catch (Throwable) {
            [$size, $head] = [0, ''];
        }

        if ($size === 0 || $head === '') {
            throw new ReadRefused(self::NOT_READABLE, __('This file could not be found. Upload it again.'), [], 422);
        }

        $type = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($head);

        return match (true) {
            $type === 'application/pdf' && str_starts_with($head, '%PDF-') => ['pdf', $size],
            in_array($type, ['image/jpeg', 'image/png'], true) => ['image', $size],
            default => throw new ReadRefused(self::NOT_READABLE, __('Only PDF and image files can be read.'), [], 422),
        };
    }

    /** The first bytes of a stored file, enough to tell its type. */
    private function head(string $path): string
    {
        $stream = Storage::readStream($path);

        if (! is_resource($stream)) {
            return '';
        }

        try {
            $head = '';

            while (strlen($head) < 65536 && ! feof($stream)) {
                $chunk = fread($stream, 65536 - strlen($head));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $head .= $chunk;
            }

            return $head;
        } finally {
            fclose($stream);
        }
    }

    /** @throws ReadRefused */
    private function assertNotBusy(int $organizationId): void
    {
        $reads = fn () => DocumentRead::withoutGlobalScopes()->where('organization_id', $organizationId)->where('kind', TenderReadKind::KEY);

        // A draft is a prepare waiting for its worker; one older than the time tending allows a prepare no longer counts.
        $going = $reads()->where(fn ($query) => $query->where('status', DocumentRead::PREPARING)
            ->orWhere(fn ($draft) => $draft->where('status', DocumentRead::DRAFT)->where('created_at', '>', now()->subMinutes(5))))
            ->count();

        if ($going >= max(1, TenderFields::setting('prepares_at_once', 1))) {
            throw $this->busy();
        }

        if ($reads()->where('created_at', '>', now()->subHour())->count() >= max(1, TenderFields::setting('prepares_per_hour', 10))) {
            throw $this->busy();
        }
    }

    private function busy(): ReadRefused
    {
        return new ReadRefused(self::BUSY, __('Another document is being prepared for reading, or ten were started in the last hour. Try again in a few minutes.'), [], 409);
    }
}
