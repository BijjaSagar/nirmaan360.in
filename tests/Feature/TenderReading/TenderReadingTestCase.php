<?php

namespace Tests\Feature\TenderReading;

use App\Models\AiDocumentSettings;
use App\Models\CrmTender;
use App\Models\CrmTenderDocument;
use App\Models\CrmTenderFact;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\Organization;
use App\Models\User;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\PageReader;
use App\Services\DocumentReading\ReadDesk;
use App\Services\DocumentReading\ReadFiles;
use App\Services\TenderReading\TenderReadKind;
use App\Services\TenderReading\TenderReads;
use App\Services\TenderReading\TenderTextPreparer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\DocumentReading\ReadingTestCase;
use Tests\Feature\Reports\WritesPdfByHand;

/**
 * A company that may use the tender reader: released, connected, consent on, credits in the wallet,
 * and a tender with a typed PDF notice. The tender reader is registered here, as a deployment lists
 * it, and the provider is faked: no test reaches the real AI service.
 *
 * Every company, figure and line of tender text is invented for the tests.
 */
abstract class TenderReadingTestCase extends ReadingTestCase
{
    use WritesPdfByHand;

    protected Organization $organization;

    protected User $owner;

    protected User $pm;

    protected User $qs;

    protected CrmTender $tender;

    /** @var list<array<string, mixed>> Every body sent to the Messages address, in order. */
    protected array $sent = [];

    protected int $counted = 0;

    /** A notice of three typed pages. Page 2 states the deposit and the dates, page 3 the periods. */
    protected const NOTICE = [
        ['Office of the Executive Engineer, Sample Works Division', 'Notice Inviting Tender No. SWD/77/2026-27', 'Name of work: Repairs to the sample approach road near Example Village'],
        ['Estimated cost put to tender: Rs. 48,60,000/-', 'Earnest Money Deposit: Rs. 61,500/- by online payment', 'Last date of online submission: 15-12-2026 up to 15:00 hrs', 'Opening of technical bid: 17-12-2026 at 11:00 hrs'],
        ['Time allowed for completion: 9 (Nine) months including monsoon', 'Defect liability period: 24 months from completion', 'Bid validity: 120 days from the date of opening'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Storage::fake();
        Queue::fake();

        config(['modules.tender_reading.read_kinds' => [TenderReadKind::class]]);

        $this->release();
        $this->connect();

        $this->organization = $this->tenant('Sahyadri Builders');
        $this->owner = $this->member($this->organization, 'owner');
        $this->pm = $this->member($this->organization, 'pm');
        $this->qs = $this->member($this->organization, 'qs');
        $this->consent($this->organization);
        $this->setCredits($this->organization, '1000');

        $this->tender = $this->makeTender($this->organization);

        // Counting and reading are faked from the start; a test that needs other answers fakes again.
        $this->fakeReader([$this->reply([])]);
    }

    protected function consent(Organization $organization, bool $on = true): void
    {
        AiDocumentSettings::for($organization)->update(['enabled' => $on, 'consent_version' => AiDocumentConsent::VERSION]);
    }

    protected function makeTender(Organization $organization, string $title = 'Repairs to the sample approach road'): CrmTender
    {
        return CrmTender::create([
            'organization_id' => $organization->id,
            'title' => $title,
            'authority' => 'Sample Works Division',
            'stage' => 'enquiry',
            'estimated_value' => '0.00',
        ]);
    }

    /** A typed PDF: each page a list of lines. */
    protected function typedPdf(array $pages = self::NOTICE): string
    {
        return $this->handPdf(array_map(fn (array $lines) => $this->textLines($lines), $pages), ['picture' => 'none']);
    }

    protected function storeDocument(CrmTender $tender, ?string $bytes = null, string $name = 'notice.pdf', ?User $by = null): CrmTenderDocument
    {
        $bytes ??= $this->typedPdf();
        $path = 'org-'.$tender->organization_id.'/crm-tenders/'.$tender->id.'/'.Str::random(8).'-'.$name;
        Storage::put($path, $bytes);

        return CrmTenderDocument::withoutGlobalScopes()->create([
            'organization_id' => $tender->organization_id,
            'tender_id' => $tender->id,
            'title' => pathinfo($name, PATHINFO_FILENAME),
            'kind' => 'nit',
            'path' => $path,
            'original_name' => $name,
            'mime' => str_ends_with($name, '.pdf') ? 'application/pdf' : 'image/jpeg',
            'size' => strlen($bytes),
            'uploaded_by' => ($by ?? $this->owner)->id,
        ]);
    }

    protected function startRead(User $user, CrmTenderDocument $document, ?CrmTender $tender = null, array $data = []): TestResponse
    {
        return $this->actingAs($user)->postJson(route('tender-reads.store', ['tender' => $tender ?? $this->tender, 'document' => $document]), $data);
    }

    /** A read created and prepared the way the routes and the queue do it. */
    protected function preparedRead(?CrmTenderDocument $document = null, ?User $by = null): DocumentRead
    {
        $document ??= $this->storeDocument($this->tender);
        $id = $this->startRead($by ?? $this->owner, $document)->assertCreated()->json('id');

        app(TenderTextPreparer::class)->prepare($id);

        return DocumentRead::withoutGlobalScopes()->findOrFail($id);
    }

    protected function answer(DocumentRead $read, string $language = 'en', string $matches = 'yes', ?User $by = null): TestResponse
    {
        return $this->actingAs($by ?? $this->owner)->post(route('tender-reads.mode', $read), ['language' => $language, 'matches' => $matches]);
    }

    /** Starts the read through the shared desk, as the screen's Start button does through the shared route. */
    protected function startReading(DocumentRead $read, ?User $by = null): DocumentRead
    {
        return app(ReadDesk::class)->start($read->refresh(), $by ?? $this->owner, null, null, null, TenderReads::picturePages($read->refresh()));
    }

    /** Runs every queued page of a read, one after the other, until none is left. */
    protected function readAll(DocumentRead $read): void
    {
        for ($round = 0; $round < 20; $round++) {
            $page = DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->where('status', DocumentReadPage::QUEUED)->orderBy('page_no')->first();

            if ($page === null) {
                return;
            }

            app(PageReader::class)->run($page->id);
        }
    }

    /** @var list<mixed> What the Messages address answers next; the last one repeats. */
    protected array $replies = [];

    protected int $tokens = 1500;

    /**
     * Answers the Messages address with these replies in turn; the last one repeats. A reply is a
     * body, a ready response, or a function of the request body that returns either. The fake itself
     * is set up once, in setUp; calling this again only changes what it answers.
     *
     * @param  list<mixed>  $replies
     */
    protected function fakeReader(array $replies, int $tokens = 1500): void
    {
        $this->replies = $replies;
        $this->tokens = $tokens;

        if ($this->providerFaked) {
            return;
        }

        $this->providerFaked = true;

        Http::fake([
            'api.anthropic.com/v1/messages/count_tokens' => function () {
                $this->counted++;

                return Http::response(['input_tokens' => $this->tokens]);
            },
            'api.anthropic.com/v1/messages' => function (Request $request) {
                $this->sent[] = $request->data();
                $reply = count($this->replies) > 1 ? array_shift($this->replies) : $this->replies[0];
                $reply = is_callable($reply) ? $reply($request->data()) : $reply;

                return is_array($reply) ? Http::response($reply, 200, ['request-id' => 'req_test_'.count($this->sent)]) : $reply;
            },
        ]);
    }

    private bool $providerFaked = false;

    /** A reply in the tender reader's form. */
    protected function reply(array $facts, array $unreadable = [], string $stop = 'end_turn', int $output = 900): array
    {
        return [
            'id' => 'msg_test_'.uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => json_encode(['facts' => $facts, 'unreadable_pages' => $unreadable], JSON_UNESCAPED_UNICODE)]],
            'stop_reason' => $stop,
            'usage' => ['input_tokens' => 1500, 'output_tokens' => $output],
        ];
    }

    /** @return array{field: string, label: string, value: string, page: int, quote: string} */
    protected function fact(string $field, string $value, int $page, string $quote, string $label = ''): array
    {
        return ['field' => $field, 'label' => $label, 'value' => $value, 'page' => $page, 'quote' => $quote];
    }

    /** A read whose text is stored as given, prepared and answered, without a PDF: for the checks on what the reader returns. */
    protected function readWithText(array $texts, ?CrmTenderDocument $document = null): DocumentRead
    {
        $document ??= $this->storeDocument($this->tender);

        $read = DocumentRead::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'kind' => TenderReadKind::KEY,
            'status' => DocumentRead::PREPARED,
            'user_id' => $this->owner->id,
            'subject_type' => (new CrmTenderDocument)->getMorphClass(),
            'subject_id' => $document->id,
            'pages_total' => count($texts),
            'consent_version' => AiDocumentConsent::VERSION,
            'options' => ['tender_id' => $this->tender->id, 'document_id' => $document->id, 'letters' => (int) preg_match_all('/\p{L}/u', implode('', $texts)), 'devanagari' => (int) preg_match_all('/(?=\p{L})\p{Devanagari}/u', implode('', $texts)), 'page_flags' => [], 'runs' => [[1, count($texts), array_sum(array_map(mb_strlen(...), $texts)), 0]]],
        ]);

        ReadFiles::putTexts($read, array_combine(range(1, count($texts)), $texts));

        DocumentReadPage::withoutGlobalScopes()->create([
            'document_read_id' => $read->id,
            'page_no' => 1,
            'page_to' => count($texts),
            'pass' => TenderReadKind::TEXT,
            'model' => 'claude-sonnet-5-5',
            'text_chars' => array_sum(array_map(mb_strlen(...), $texts)),
        ]);

        return $read->refresh();
    }

    /** The facts of the tender, in the order they were staged. */
    protected function facts(?CrmTender $tender = null): Collection
    {
        return CrmTenderFact::withoutGlobalScopes()->where('tender_id', ($tender ?? $this->tender)->id)->orderBy('id')->get();
    }
}
