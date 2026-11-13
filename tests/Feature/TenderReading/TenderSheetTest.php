<?php

namespace Tests\Feature\TenderReading;

use App\Models\AuditLog;
use App\Models\CrmTenderFact;
use App\Models\DocumentRead;
use App\Services\TenderReading\TenderFactBuilder;
use App\Services\TenderReading\TenderFields;
use App\Services\TenderReading\TenderValue;

/** The tender sheet: who sees it, confirming each fact with its page, and the checks code makes. */
class TenderSheetTest extends TenderReadingTestCase
{
    private function makeFact(string $field, string $raw, int $page = 1, array $overrides = []): CrmTenderFact
    {
        $kind = TenderFields::kind($field);

        $fact = CrmTenderFact::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'tender_id' => $this->tender->id,
            'field' => $field,
            'value_raw' => $raw,
            'value_norm' => TenderValue::normalise($kind, $raw),
            'value_kind' => $kind,
            'page' => $page,
            'quote' => 'The line of page '.$page.' that holds '.$raw,
            'quote_line' => 'The line of page '.$page.' that holds '.$raw,
            'quote_found' => true,
            'source' => 'text',
            ...$overrides,
        ]);

        app(TenderFactBuilder::class)->regroup($this->organization->id, $this->tender->id);

        return $fact->refresh();
    }

    public function test_a_fact_is_confirmed_only_with_its_page_viewed(): void
    {
        $fact = $this->makeFact('emd_amount', 'Rs. 61,500/-');

        $this->actingAs($this->owner)->post(route('tender-facts.confirm', $fact))
            ->assertSessionHasErrors(['viewed' => 'Open the page beside this fact and check it before you confirm it.']);
        $this->actingAs($this->owner)->postJson(route('tender-facts.confirm', $fact), ['viewed' => false])->assertStatus(422);
        $this->assertSame(CrmTenderFact::PROPOSED, $fact->refresh()->status);

        $this->actingAs($this->pm)->postJson(route('tender-facts.confirm', $fact), ['viewed' => true])->assertOk()->assertJson(['status' => 'confirmed']);
        $fact->refresh();
        $this->assertSame([CrmTenderFact::CONFIRMED, true, $this->pm->id], [$fact->status, $fact->page_viewed, (int) $fact->confirmed_by]);

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'tender_fact.confirmed')->sole();
        $this->assertSame(['status' => 'confirmed', 'page_viewed' => true, 'field' => 'emd_amount', 'page' => 1, 'source' => 'text', 'quote_found' => true, 'edited' => false], $audit->new_values);

        // Confirming twice changes nothing.
        $this->actingAs($this->pm)->postJson(route('tender-facts.confirm', $fact), ['viewed' => true])->assertStatus(409)->assertJson(['code' => 'not_proposed']);

        // Nothing reached the tender.
        $this->assertSame('0.00', (string) $this->tender->refresh()->estimated_value);
    }

    public function test_the_page_is_drawn_from_the_tenders_own_document_beside_the_fact(): void
    {
        $document = $this->storeDocument($this->tender);
        $fact = $this->makeFact('due_at', '15-12-2026 up to 15:00 hrs', 2, ['document_id' => $document->id]);

        $page = $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk();
        $page->assertSee('Show page 2');
        $page->assertSee(route('crm.documents.download', $document), false);
        $page->assertSee('Confirm against the page');
        $page->assertSee('name="viewed" value="0"', false);
        $page->assertSee('Read by the app as 15 Dec 2026, 03:00 PM');
        $page->assertSee('This sheet is a reading aid. Read the tender conditions yourself. A corrigendum is seen only if you upload it.');
        $page->assertSee("The quoted line matches the PDF's text on that page. The page is shown beside it for you to check.");

        // Once the document is removed from the tender its page cannot be drawn, so nothing can be confirmed against it.
        $document->delete();
        $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk()
            ->assertSee('The document has been removed from the tender, so its page cannot be drawn.')
            ->assertDontSee('Show page 2');
        $this->assertSame(CrmTenderFact::PROPOSED, $fact->refresh()->status);
    }

    public function test_qs_reads_the_sheet_and_cannot_change_it(): void
    {
        $fact = $this->makeFact('emd_amount', 'Rs. 61,500/-');

        $this->actingAs($this->qs)->get(route('tender-facts.index', $this->tender))->assertOk()->assertSee('Rs. 61,500/-')->assertDontSee('Confirm against the page');
        $this->actingAs($this->qs)->post(route('tender-facts.confirm', $fact), ['viewed' => 1])->assertForbidden();
        $this->actingAs($this->qs)->patch(route('tender-facts.update', $fact), ['value' => 'Rs. 1/-'])->assertForbidden();
        $this->actingAs($this->qs)->post(route('tender-facts.reject', $fact))->assertForbidden();

        $this->actingAs($this->member($this->organization, 'engineer'))->get(route('tender-facts.index', $this->tender))->assertForbidden();
        $this->assertSame([CrmTenderFact::PROPOSED, 'Rs. 61,500/-'], [$fact->refresh()->status, $fact->value_raw]);
    }

    public function test_another_companys_fact_is_not_found(): void
    {
        $fact = $this->makeFact('emd_amount', 'Rs. 61,500/-');
        $outsider = $this->member($this->tenant('Godavari Infra'), 'owner');

        $this->actingAs($outsider)->post(route('tender-facts.confirm', $fact), ['viewed' => 1])->assertNotFound();
        $this->actingAs($outsider)->patch(route('tender-facts.update', $fact), ['value' => 'Rs. 1/-'])->assertNotFound();
        $this->actingAs($outsider)->post(route('tender-facts.reject', $fact))->assertNotFound();
        $this->assertSame(CrmTenderFact::PROPOSED, $fact->refresh()->status);
    }

    public function test_a_value_is_corrected_while_it_is_a_proposal_and_the_contradiction_follows(): void
    {
        $one = $this->makeFact('emd_amount', 'Rs. 61,500/-', 1);
        $two = $this->makeFact('emd_amount', 'Rs. 65,100/-', 2);
        $this->assertNotNull($one->refresh()->conflict_group);

        $this->actingAs($this->owner)->patch(route('tender-facts.update', $two), ['value' => 'Rs. 61,500/-'])->assertRedirect(route('tender-facts.index', $this->tender).'#fact-'.$two->id);

        $two->refresh();
        $this->assertSame(['Rs. 61,500/-', '61500', true, CrmTenderFact::PROPOSED], [$two->value_raw, $two->value_norm, $two->edited, $two->status]);
        $this->assertNull($one->refresh()->conflict_group);

        $edit = AuditLog::withoutGlobalScopes()->where('action', 'tender_fact.edited')->sole();
        $this->assertStringNotContainsString('61,500', json_encode([$edit->old_values, $edit->new_values]));

        // A confirmed fact is not edited any more; it can be rejected until it is applied.
        $this->actingAs($this->owner)->post(route('tender-facts.confirm', $one), ['viewed' => 1]);
        $this->actingAs($this->owner)->patchJson(route('tender-facts.update', $one), ['value' => 'Rs. 1/-'])->assertStatus(409);
        $this->actingAs($this->owner)->postJson(route('tender-facts.reject', $one))->assertOk()->assertJson(['status' => 'rejected']);
        $this->actingAs($this->owner)->postJson(route('tender-facts.reject', $one))->assertStatus(409);

        $applied = $this->makeFact('tender_fee', 'Rs. 2,950/-', 1, ['status' => CrmTenderFact::CONFIRMED, 'applied_to' => 'payment:1']);
        $this->actingAs($this->owner)->postJson(route('tender-facts.reject', $applied))->assertStatus(409);
    }

    public function test_a_read_counts_as_confirmed_once_no_fact_of_it_is_left_to_look_at(): void
    {
        $read = $this->readWithText(["Earnest Money Deposit: Rs. 61,500/- by online payment\nTender fee: Rs. 2,950/- non refundable"]);
        $this->fakeReader([$this->reply([
            $this->fact('emd_amount', 'Rs. 61,500/-', 1, 'Earnest Money Deposit: Rs. 61,500/- by online payment'),
            $this->fact('tender_fee', 'Rs. 2,950/-', 1, 'Tender fee: Rs. 2,950/- non refundable'),
        ])]);
        $this->answer($read);
        $this->startReading($read);
        $this->readAll($read);
        $this->assertSame(DocumentRead::READY, $read->refresh()->status);

        [$emd, $fee] = $this->facts()->all();
        $this->actingAs($this->owner)->post(route('tender-facts.confirm', $emd), ['viewed' => 1]);
        $this->assertSame(DocumentRead::READY, $read->refresh()->status);

        $this->actingAs($this->owner)->patch(route('tender-facts.update', $fee), ['value' => 'Rs. 2,590/-']);
        $this->actingAs($this->owner)->post(route('tender-facts.reject', $fee));
        $this->assertSame(DocumentRead::CONFIRMED, $read->refresh()->status);

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'ai.read_confirmed')->sole();
        $this->assertSame(['kind' => 'tender', 'rows_one_by_one' => 1, 'cells_typed' => 1], $audit->new_values);
    }

    public function test_the_deposit_is_worked_out_only_from_two_confirmed_inputs(): void
    {
        $percent = $this->makeFact('emd_percent', '1.37% of estimated cost');
        $estimate = $this->makeFact('estimated_value', 'Rs. 48,60,000/-', 1);
        $this->makeFact('emd_amount', 'Rs. 66,582/-', 2);
        $this->makeFact('emd_amount', 'Rs. 61,500/-', 3);

        $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk()->assertDontSee('EMD worked out by the app');

        $this->actingAs($this->owner)->post(route('tender-facts.confirm', $percent), ['viewed' => 1]);
        $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk()->assertDontSee('EMD worked out by the app');

        $this->actingAs($this->owner)->post(route('tender-facts.confirm', $estimate), ['viewed' => 1]);
        $page = $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk();

        // 48,60,000 x 1.37 / 100 = 66,582.00, worked out with exact decimals.
        $page->assertSee('1.37% of ₹48,60,000.00 is ₹66,582.00.');
        $page->assertSee('The EMD amount printed on page 2 is ₹66,582.00, the same as the worked-out figure.');
        $page->assertSee('The EMD amount printed on page 3 is ₹61,500.00. It differs from the worked-out figure.');
    }

    public function test_dates_out_of_order_and_dates_already_past_are_warnings(): void
    {
        $this->travelTo(now('Asia/Kolkata')->setDate(2026, 12, 1));

        $this->makeFact('pre_bid_at', '20-12-2026 at 11:00 hrs');
        $this->makeFact('due_at', '15-12-2026 up to 15:00 hrs');
        $this->makeFact('opens_at', '28-11-2026 at 11:00 hrs');

        $page = $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk();
        $page->assertSee('Submission deadline comes before Pre-bid meeting on this sheet. Check both against the pages.');
        $page->assertSee('Bid opening on this sheet is already past.');

        // Where the tender states two deadlines, the contradiction is the warning; neither date is used for the order.
        $this->makeFact('due_at', '22-12-2026 up to 15:00 hrs', 4);
        $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk()
            ->assertDontSee('Submission deadline comes before Pre-bid meeting');
    }

    public function test_a_link_read_from_a_document_is_shown_as_text_and_never_as_a_link(): void
    {
        $this->makeFact('portal_url', 'javascript:alert(document.cookie)');
        $this->makeFact('portal_url', 'https://tenders.example.test/notice/77', 2);

        $page = $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk();
        $page->assertSee('javascript:alert(document.cookie)');
        $html = (string) $page->getContent();
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringNotContainsString('href="https://tenders.example.test', $html);
        $this->assertNull(TenderValue::url('javascript:alert(1)'));
    }

    public function test_pages_not_read_are_listed_by_number_with_the_reason(): void
    {
        $scan = $this->pagePicture().$this->textLines(['Earnest Money Deposit Rs. 1,00,000/-', 'Last date 01-01-2027'], 3);
        $pdf = $this->handPdf([$this->textLines(self::NOTICE[1]), $scan, $this->textLines(self::NOTICE[2])]);
        $read = $this->preparedRead($this->storeDocument($this->tender, $pdf));

        $this->fakeReader([$this->reply([$this->fact('bid_validity', '120 days', 3, 'Bid validity: 120 days from the date of opening')], [3])]);
        $this->answer($read);
        $this->startReading($read);
        $this->readAll($read);

        $page = $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk();
        $page->assertSee('Pages not read: 2, because its text was laid over a scan by a machine.');
        $page->assertSee('Pages not read: 3, because the reader could not make sense of its text.');
        $page->assertSee('120 days');
    }
}
