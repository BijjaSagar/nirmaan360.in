<?php

namespace App\Services\TenderReading;

use App\Models\CrmTenderDocument;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Services\Agents\ModelGateway;
use App\Services\Agents\ModelGatewayException;
use App\Services\DocumentReading\PageCall;
use App\Services\DocumentReading\ReaderRequest;
use App\Services\DocumentReading\ReadFiles;
use App\Services\DocumentReading\ReadKinds;
use App\Services\DocumentReading\ReadLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Prepares a tender read: takes the text out of the PDF page by page, stores it with the read, marks
 * the pages whose text cannot be trusted, and lays the rest out as runs of text pages to be read.
 * Nothing is sent to be read here; the person first says whether the text matches the pages.
 *
 * It runs on the queue with nobody signed in, so every query names the company. A second delivery of
 * the same job finds the read no longer a draft and does nothing.
 */
class TenderTextPreparer
{
    /** Counting a run's tokens is given up on after this long in all, and the quote uses an estimate. */
    private const COUNTING_SECONDS = 30;

    public function __construct(
        private TenderPdfText $pdf,
        private ReadLifecycle $lifecycle,
        private ReadKinds $kinds,
        private ModelGateway $gateway,
        private PageCall $call,
    ) {}

    public function prepare(int $readId): void
    {
        $claimed = DocumentRead::withoutGlobalScopes()
            ->whereKey($readId)
            ->where('kind', TenderReadKind::KEY)
            ->where('status', DocumentRead::DRAFT)
            ->update(['status' => DocumentRead::PREPARING, 'prepare_started_at' => now(), 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $read = DocumentRead::withoutGlobalScopes()->findOrFail($readId);

        try {
            $pages = $this->extract($read);
        } catch (TenderTextRefused $refused) {
            $this->fail($read, $refused);

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->fail($read, new TenderTextRefused(TenderTextRefused::UNREADABLE));

            return;
        }

        $this->store($read, $pages);
    }

    /**
     * @return list<array{text: string, chars: int, flags: list<string>, letters: int, devanagari: int}>
     *
     * @throws TenderTextRefused
     */
    private function extract(DocumentRead $read): array
    {
        $document = CrmTenderDocument::withoutGlobalScopes()
            ->where('organization_id', $read->organization_id)
            ->where('tender_id', (int) ($read->options['tender_id'] ?? 0))
            ->whereNull('deleted_at')
            ->find((int) ($read->options['document_id'] ?? 0));

        try {
            $bytes = $document === null ? null : Storage::get($document->path);
        } catch (Throwable) {
            $bytes = null;
        }

        if (! is_string($bytes) || $bytes === '') {
            throw new TenderTextRefused(TenderTextRefused::DOCUMENT_GONE);
        }

        TenderPdfText::assertPdf($bytes);

        $read->forceFill(['source_sha256' => hash('sha256', $bytes), 'source_bytes' => strlen($bytes)])->save();

        // The disk is a bucket on the live site, and the parser needs a file: a copy lives here only while it is read.
        $path = tempnam(sys_get_temp_dir(), 'tender');

        try {
            if ($path === false || file_put_contents($path, $bytes) === false) {
                throw new TenderTextRefused(TenderTextRefused::UNREADABLE);
            }

            unset($bytes);

            return $this->pdf->read($path);
        } finally {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** @param  list<array{text: string, chars: int, flags: list<string>, letters: int, devanagari: int}>  $pages */
    private function store(DocumentRead $read, array $pages): void
    {
        $texts = [];
        $flags = [];

        foreach ($pages as $index => $page) {
            $texts[$index + 1] = $page['text'];

            if ($page['flags'] !== []) {
                $flags[$index + 1] = $page['flags'];
            }
        }

        try {
            ReadFiles::putTexts($read, $texts);
        } catch (Throwable $exception) {
            report($exception);
            $this->fail($read, new TenderTextRefused(TenderTextRefused::UNREADABLE));

            return;
        }

        $read->pages_total = count($pages);
        $rows = $this->runs($read, $pages, $flags, $texts);
        $this->count($read, $rows, $texts);

        DB::transaction(function () use ($read, $pages, $flags, $rows) {
            $locked = DocumentRead::withoutGlobalScopes()->where('organization_id', $read->organization_id)->lockForUpdate()->find($read->id);

            // Tending may have given up on it meanwhile.
            if ($locked === null || $locked->status !== DocumentRead::PREPARING) {
                return;
            }

            foreach ($rows as $row) {
                $row->unsetRelation('documentRead');
                $row->save();
            }

            $letters = array_sum(array_column($pages, 'letters'));

            $locked->forceFill([
                'status' => DocumentRead::PREPARED,
                'pages_total' => count($pages),
                'options' => [
                    ...(array) $locked->options,
                    'page_flags' => $flags,
                    'letters' => $letters,
                    'devanagari' => array_sum(array_column($pages, 'devanagari')),
                    'samples' => $this->samples($pages, $flags),
                    // The runs as they were laid out, so the answers before the price can set them out again.
                    'runs' => array_map(fn (DocumentReadPage $row) => [(int) $row->page_no, (int) $row->page_to, (int) $row->text_chars, (int) $row->input_tokens], $rows),
                ],
            ])->save();
        });
    }

    /**
     * Runs of pages that follow each other, have usable text and fit the run's limits.
     *
     * @param  array<int, list<string>>  $flags
     * @param  array<int, string>  $texts
     * @return list<DocumentReadPage> Not saved yet.
     */
    private function runs(DocumentRead $read, array $pages, array $flags, array $texts): array
    {
        $mostPages = max(1, TenderFields::setting('run_pages', 8));
        $mostChars = max(1, TenderFields::setting('run_chars', 24000));
        $model = (string) ReaderRequest::figures(TenderReadKind::KEY, TenderReadKind::TEXT)['model'];
        $rows = [];
        $run = [];
        $chars = 0;

        $close = function () use (&$run, &$chars, &$rows, $read, $model, $texts) {
            if ($run === []) {
                return;
            }

            $row = new DocumentReadPage([
                'document_read_id' => $read->id,
                'page_no' => $run[0],
                'page_to' => end($run),
                'pass' => TenderReadKind::TEXT,
                'part' => 'full',
                'status' => DocumentReadPage::WAITING,
                'model' => $model,
                'text_chars' => array_sum(array_map(fn (int $number) => mb_strlen($texts[$number]), $run)),
            ]);
            $row->setRelation('documentRead', $read);

            $rows[] = $row;
            $run = [];
            $chars = 0;
        };

        foreach (array_keys($pages) as $index) {
            $number = $index + 1;

            if (isset($flags[$number])) {
                $close();

                continue;
            }

            $length = mb_strlen($texts[$number]);

            if ($run !== [] && (count($run) >= $mostPages || $chars + $length > $mostChars || end($run) !== $number - 1)) {
                $close();
            }

            $run[] = $number;
            $chars += $length;
        }

        $close();

        return $rows;
    }

    /**
     * Asks the provider how many tokens each run will be, so the quote is close. Counting reads
     * nothing and costs nothing. Without a key, or when counting fails, the quote estimates instead.
     *
     * @param  list<DocumentReadPage>  $rows
     * @param  array<int, string>  $texts
     */
    private function count(DocumentRead $read, array $rows, array $texts): void
    {
        $kind = $this->kinds->find(TenderReadKind::KEY);

        if ($kind === null || ! $this->gateway->isConnected()) {
            return;
        }

        $until = hrtime(true) + self::COUNTING_SECONDS * 1_000_000_000;

        foreach ($rows as $row) {
            if (hrtime(true) > $until) {
                return;
            }

            $run = array_intersect_key($texts, array_flip(range((int) $row->page_no, (int) $row->page_to)));

            try {
                $row->input_tokens = $this->gateway->countTokens($this->call->body($kind, $row, null, ReaderRequest::pages($run)));
            } catch (ModelGatewayException) {
                return;
            }
        }
    }

    /**
     * The pages the person is shown to judge the text by: the first, the middle and the one with the
     * most text, among the pages that have usable text.
     *
     * @param  array<int, list<string>>  $flags
     * @return list<int>
     */
    private function samples(array $pages, array $flags): array
    {
        $usable = [];

        foreach ($pages as $index => $page) {
            if (! isset($flags[$index + 1])) {
                $usable[$index + 1] = $page['chars'];
            }
        }

        if ($usable === []) {
            return [];
        }

        $numbers = array_keys($usable);
        arsort($usable);

        $picked = array_values(array_unique([$numbers[0], $numbers[intdiv(count($numbers) - 1, 2)], (int) array_key_first($usable)]));

        // When the middle page is also the fullest, the last page is the third, then the ones before it.
        foreach (array_reverse($numbers) as $number) {
            if (count($picked) >= 3) {
                break;
            }

            if (! in_array($number, $picked, true)) {
                $picked[] = $number;
            }
        }

        sort($picked);

        return $picked;
    }

    private function fail(DocumentRead $read, TenderTextRefused $refused): void
    {
        if ($refused->details !== []) {
            DocumentRead::withoutGlobalScopes()->where('organization_id', $read->organization_id)->whereKey($read->id)
                ->update(['options' => json_encode([...(array) $read->options, 'refused' => $refused->details])]);
        }

        $this->lifecycle->failPreparing($read->refresh(), $refused->reason);
    }
}
