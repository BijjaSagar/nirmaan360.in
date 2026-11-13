<?php

namespace App\Http\Controllers\TenderReading;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DocumentReading\ChecksParent;
use App\Models\CrmTender;
use App\Models\CrmTenderDocument;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Services\DocumentReading\ReadAccess;
use App\Services\DocumentReading\ReadFiles;
use App\Services\DocumentReading\ReadKinds;
use App\Services\DocumentReading\ReadRefused;
use App\Services\TenderReading\TenderReadKind;
use App\Services\TenderReading\TenderReads;
use App\Services\TenderReading\TenderReadWords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Reading a tender document: starting the read, the answers before its price, and its one screen,
 * which shows each step in turn. Pricing, starting and watching go through the shared reader routes.
 */
class TenderReadController extends Controller
{
    use ChecksParent;

    public function __construct(private TenderReads $reads, private ReadKinds $kinds) {}

    public function store(Request $request, CrmTender $tender, CrmTenderDocument $document): JsonResponse|RedirectResponse
    {
        $this->assertChildOf($document, $tender, 'tender_id');
        abort_if($this->kinds->find(TenderReadKind::KEY) === null, 404);

        $data = $request->validate(['client_uuid' => ['nullable', 'uuid']]);

        try {
            $read = $this->reads->open($tender, $document, $request->user(), $data['client_uuid'] ?? null);
        } catch (ReadRefused $refused) {
            if ($request->expectsJson()) {
                return response()->json([...$refused->toArray(), 'message' => $refused->getMessage()], $refused->status);
            }

            return back()->withErrors(['tender_read' => $refused->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json(['id' => $read->id, 'status' => $read->status], $read->wasRecentlyCreated ? 201 : 200);
        }

        return redirect()->route('tender-reads.view', $read);
    }

    public function show(Request $request, DocumentRead $read): View
    {
        $this->assertTenderRead($read, $request);

        $tender = CrmTender::query()->find((int) ($read->options['tender_id'] ?? 0));
        abort_if($tender === null, 404);

        $document = CrmTenderDocument::query()->where('tender_id', $tender->id)->find((int) ($read->options['document_id'] ?? 0));
        $state = $this->state($read);

        return view('modules.tender_reading.read', [
            'read' => $read,
            'tender' => $tender,
            'document' => $document,
            'state' => $state,
            'error' => $state === 'prepare_failed' ? TenderReadWords::error($read) : null,
            'samples' => in_array($state, ['questions', 'quote'], true) ? $this->samples($read) : [],
            'pictures' => TenderReads::answered($read) ? TenderReads::picturePages($read) : [],
            'textPages' => $this->textPages($read),
            'range' => (array) ($read->options['range'] ?? []),
        ]);
    }

    public function mode(Request $request, DocumentRead $read): JsonResponse|RedirectResponse
    {
        $this->assertTenderRead($read, $request);

        $data = $request->validate([
            'language' => ['required', Rule::in(TenderReads::LANGUAGES)],
            'matches' => ['required', Rule::in(['yes', 'no'])],
            'page_from' => ['nullable', 'integer', 'min:1'],
            'page_to' => ['nullable', 'integer', 'min:1'],
        ], [
            'language.required' => __('Choose the language the document is written in.'),
            'matches.required' => __('Say whether the text on the left matches the page on the right.'),
        ]);

        $from = isset($data['page_from']) ? (int) $data['page_from'] : null;
        $to = isset($data['page_to']) ? (int) $data['page_to'] : null;

        if ($from !== null && $to !== null && $to < $from) {
            throw ValidationException::withMessages(['page_to' => [__('The last page cannot come before the first page.')]]);
        }

        try {
            $pictures = $this->reads->answer($read, $request->user(), $data['language'], $data['matches'] === 'yes', ['page_from' => $from, 'page_to' => $to]);
        } catch (ReadRefused $refused) {
            if ($request->expectsJson()) {
                return response()->json([...$refused->toArray(), 'message' => $refused->getMessage()], $refused->status);
            }

            return back()->withErrors(['tender_read' => $refused->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json(['id' => $read->id, 'status' => $read->status, 'picture_pages' => $pictures]);
        }

        return redirect()->route('tender-reads.view', $read);
    }

    /** A tender read, opened by someone who may open it. Any other read is not found here. */
    private function assertTenderRead(DocumentRead $read, Request $request): void
    {
        abort_unless($read->kind === TenderReadKind::KEY, 404);
        ReadAccess::assert($read, $request->user());
    }

    /** Which step the screen shows. */
    private function state(DocumentRead $read): string
    {
        return match ($read->status) {
            DocumentRead::DRAFT, DocumentRead::PREPARING => 'preparing',
            DocumentRead::PREPARED, DocumentRead::QUOTED => TenderReads::answered($read) ? 'quote' : 'questions',
            DocumentRead::RUNNING, DocumentRead::PAUSED => 'running',
            DocumentRead::FAILED => $read->started_at === null ? 'prepare_failed' : 'done',
            DocumentRead::CANCELLED, DocumentRead::DISCARDED => 'cancelled',
            default => 'done',
        };
    }

    /**
     * The pages the person compares, with the text the server took out of each.
     *
     * @return list<array{page: int, text: string}>
     */
    private function samples(DocumentRead $read): array
    {
        $samples = [];

        foreach ((array) ($read->options['samples'] ?? []) as $number) {
            try {
                $text = ReadFiles::texts($read, (int) $number, (int) $number)[(int) $number];
            } catch (RuntimeException) {
                continue;
            }

            $samples[] = ['page' => (int) $number, 'text' => $text];
        }

        return $samples;
    }

    /** @return list<int> The pages waiting to be read as text. */
    private function textPages(DocumentRead $read): array
    {
        $pages = [];

        $rows = DocumentReadPage::withoutGlobalScopes()->where('organization_id', $read->organization_id)
            ->where('document_read_id', $read->id)->where('pass', TenderReadKind::TEXT)
            ->where('status', DocumentReadPage::WAITING)->get(['page_no', 'page_to']);

        foreach ($rows as $row) {
            array_push($pages, ...range((int) $row->page_no, max((int) $row->page_no, (int) $row->page_to)));
        }

        sort($pages);

        return $pages;
    }
}
