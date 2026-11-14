<?php

namespace App\Http\Controllers\TenderReading;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DocumentReading\ChecksParent;
use App\Models\CrmTender;
use App\Models\CrmTenderDocument;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Services\Agents\ModelGateway;
use App\Services\DocumentReading\ReadAccess;
use App\Services\DocumentReading\ReadFiles;
use App\Services\DocumentReading\ReadKinds;
use App\Services\DocumentReading\ReadRefused;
use App\Services\DocumentReading\ReadWords;
use App\Services\TenderReading\TenderFields;
use App\Services\TenderReading\TenderReadKind;
use App\Services\TenderReading\TenderReads;
use App\Services\TenderReading\TenderReadWords;
use App\Services\TenderReading\TenderSheet;
use App\Services\TenderReading\TenderTextRefused;
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

    public function __construct(private TenderReads $reads, private ReadKinds $kinds, private ModelGateway $gateway, private TenderSheet $sheet) {}

    public function store(Request $request, CrmTender $tender, CrmTenderDocument $document): JsonResponse|RedirectResponse
    {
        $this->assertChildOf($document, $tender, 'tender_id');
        abort_if($this->kinds->find(TenderReadKind::KEY) === null, 404);

        $data = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'as' => ['nullable', Rule::in([TenderReads::AS_PICTURES])],
        ]);

        try {
            $read = $this->reads->open($tender, $document, $request->user(), $data['client_uuid'] ?? null, $data['as'] ?? null);
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
        // Watching needs crm.view only (the route checks it); every step that sends pages needs crm.manage.
        abort_unless($read->kind === TenderReadKind::KEY && $this->kinds->find(TenderReadKind::KEY) !== null, 404);
        abort_unless((int) $read->organization_id === (int) $request->user()->organization_id, 404);

        $tender = CrmTender::query()->find((int) ($read->options['tender_id'] ?? 0));
        abort_if($tender === null, 404);

        $document = CrmTenderDocument::query()->where('tender_id', $tender->id)->find((int) ($read->options['document_id'] ?? 0));
        $state = $this->state($read);
        $source = TenderReads::source($read);
        $toRead = TenderReads::picturesToRead($read);

        return view('modules.tender_reading.read', [
            'read' => $read,
            'tender' => $tender,
            'document' => $document,
            'state' => $state,
            'source' => $source,
            'error' => $state === 'prepare_failed' ? TenderReadWords::error($read) : null,
            // A PDF whose text could not be taken out for its size, length or time can still be read as page pictures,
            // as can one whose prepare never finished (its worker ran out of memory or time), which tending ends.
            'offerPictures' => $state === 'prepare_failed' && $document !== null
                && in_array($read->error, [TenderTextRefused::TOO_MANY_PAGES, TenderTextRefused::TOO_LARGE, TenderTextRefused::TOO_SLOW, ReadWords::TOO_LARGE_FOR_TEXT], true),
            // The prepare sends each run's text to the AI service to count its tokens when a key is saved (TenderTextPreparer::count).
            'counting' => $state === 'preparing' && $this->gateway->isConnected(),
            'samples' => in_array($state, ['questions', 'quote'], true) && $source === TenderReads::SOURCE_TEXT ? $this->samples($read) : [],
            'pictures' => TenderReads::answered($read) ? TenderReads::picturePages($read) : [],
            'toRead' => $toRead,
            'stored' => in_array($state, ['quote', 'running', 'done'], true) ? TenderReads::storedPictures($read) : [],
            'textPages' => $this->textPages($read),
            'range' => (array) ($read->options['range'] ?? []),
            // A PDF read as pictures may not know how many pages the whole document has; the pages it reads are its count.
            // A read that found nothing while the readers are off since has no sheet to open (TenderSheet::open).
            'sheetOpen' => $this->sheet->open($tender, $request->user()),
            'readTotal' => $source === TenderReads::SOURCE_PICTURES ? max(count(TenderReads::picturePages($read)), (int) $read->pages_read + (int) $read->pages_failed)
                : max((int) $read->pages_total, (int) $read->pages_read + (int) $read->pages_failed),
        ]);
    }

    public function mode(Request $request, DocumentRead $read): JsonResponse|RedirectResponse
    {
        $this->assertTenderRead($read, $request);

        // A read of pictures only has no text to compare; a PDF read that way is read a range at a time.
        $source = TenderReads::source($read);
        $range = $source === TenderReads::SOURCE_PICTURES ? 'required' : 'nullable';

        $data = $request->validate([
            'language' => ['required', Rule::in(TenderReads::LANGUAGES)],
            'matches' => [Rule::requiredIf($source === TenderReads::SOURCE_TEXT), 'nullable', Rule::in(['yes', 'no'])],
            'page_from' => [$range, 'integer', 'min:1'],
            'page_to' => [$range, 'integer', 'min:1'],
        ], [
            'language.required' => __('Choose the language the document is written in.'),
            'matches.required' => __('Say whether the text on the left matches the page on the right.'),
            'page_from.required' => __('Choose the pages to read as pictures.'),
            'page_to.required' => __('Choose the pages to read as pictures.'),
        ]);

        $from = isset($data['page_from']) && $source !== TenderReads::SOURCE_IMAGE ? (int) $data['page_from'] : null;
        $to = isset($data['page_to']) && $source !== TenderReads::SOURCE_IMAGE ? (int) $data['page_to'] : null;

        if ($from !== null && $to !== null && $to < $from) {
            throw ValidationException::withMessages(['page_to' => [__('The last page cannot come before the first page.')]]);
        }

        $most = max(1, TenderFields::setting('picture_pages', 50));

        if ($source === TenderReads::SOURCE_PICTURES && $to - $from + 1 > $most) {
            throw ValidationException::withMessages(['page_to' => [__('Up to :pages pages can be read as pictures at a time. Read the rest as another range.', ['pages' => $most])]]);
        }

        try {
            $pictures = $this->reads->answer($read, $request->user(), $data['language'], $source === TenderReads::SOURCE_TEXT && $data['matches'] === 'yes', ['page_from' => $from, 'page_to' => $to]);
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
