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
 */
class TenderReads
{
    /** Another prepare of the company is still going, or it has started as many as it may this hour. */
    public const BUSY = 'busy';

    /** The file is not a PDF the text can be taken out of. */
    public const NOT_READABLE = 'not_readable';

    public const LANGUAGES = ['en', 'mr', 'hi'];

    public function __construct(private ReadDesk $desk, private ReadQueues $queues) {}

    /**
     * Opens a read of one of the tender's documents and queues the taking out of its text. A repeated
     * client_uuid gives back the read it made the first time.
     *
     * @throws ReadRefused not_readable (422) or busy (409)
     * @throws ValidationException a client_uuid that stands for a read of another document
     */
    public function open(CrmTender $tender, CrmTenderDocument $document, User $by, ?string $clientUuid): DocumentRead
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

        $this->assertReadable($document);

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
                'options' => ['tender_id' => (int) $tender->id, 'document_id' => (int) $document->id],
            ]);
        } finally {
            $lock->release();
        }

        // Two requests with one key for two documents at the same moment: the second does not get the first one's read.
        if ((int) ($read->options['document_id'] ?? 0) !== (int) $document->id) {
            throw ValidationException::withMessages(['client_uuid' => [__('Record not found on the server.')]]);
        }

        if ($read->wasRecentlyCreated) {
            $job = PrepareTenderText::dispatch($read->id)->afterCommit();

            if (($queue = $this->queues->name(ReadQueues::PREPARE)) !== null) {
                $job->onQueue($queue);
            }
        }

        return $read;
    }

    /**
     * The person's answers before the read is priced: the language of the document, and whether the
     * text on the left matches the page on the right. Pages whose text cannot be trusted are set aside
     * to be read as page pictures; the rest wait to be read as text. Can be answered again until the
     * read starts.
     *
     * @param  array{page_from?: int, page_to?: int}  $range  The pages the person wants read, when not all of them.
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

            $pictures = self::picturePages($locked, $language, $matches);

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

            $locked->forceFill(['options' => [
                ...(array) $locked->options,
                'language' => $language,
                'matches' => $matches,
                'needs_picture' => $pictures,
                'range' => array_filter(['page_from' => $range['page_from'] ?? null, 'page_to' => $range['page_to'] ?? null]),
                'answered_by' => $by->id,
                'answered_at' => now()->toIso8601String(),
            ]])->save();

            AuditLog::record('tender_read.answered', $locked, null, [
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
     * or names Marathi or Hindi for a text with too few Devanagari letters.
     *
     * @return list<int>
     */
    public static function picturePages(DocumentRead $read, ?string $language = null, ?bool $matches = null): array
    {
        $options = (array) $read->options;
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

    /** @throws ReadRefused */
    private function assertReadable(CrmTenderDocument $document): void
    {
        try {
            $size = Storage::exists($document->path) ? (int) Storage::size($document->path) : 0;
        } catch (Throwable) {
            $size = 0;
        }

        if ($size === 0) {
            throw new ReadRefused(self::NOT_READABLE, __('This file could not be found. Upload it again.'), [], 422);
        }

        if ($size > TenderFields::setting('max_bytes', 10 * 1024 * 1024)) {
            throw new ReadRefused(self::NOT_READABLE, __('Only a PDF of up to :mb MB can be read as text.', ['mb' => intdiv(TenderFields::setting('max_bytes', 10 * 1024 * 1024), 1024 * 1024)]), [], 422);
        }

        $bytes = (string) Storage::get($document->path);

        try {
            TenderPdfText::assertPdf($bytes);
        } catch (TenderTextRefused) {
            $type = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

            throw new ReadRefused(self::NOT_READABLE, in_array($type, ['image/jpeg', 'image/png'], true)
                ? __('This file is a picture. Only PDF files are read here.')
                : __('Only PDF and image files can be read.'), [], 422);
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
