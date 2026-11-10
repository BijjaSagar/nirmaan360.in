<?php

namespace Tests\Feature\ContractorControl;

use App\Models\ContractorBid;
use App\Models\ContractorTender;
use App\Models\Party;
use App\Services\ContractorControl\ContractorTenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** The corners of tendering from a BOQ line: empty quantities, replaced quotes, typed names and dates. */
class TenderEdgeCasesTest extends TestCase
{
    use BuildsContractorControl, RefreshDatabase;

    private ContractorTenderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake();
        $this->site();
        $this->service = app(ContractorTenderService::class);
    }

    private function tender(): ContractorTender
    {
        return $this->service->create($this->project, ['title' => 'Flooring', 'boq_item_ids' => [$this->item->id]], $this->owner);
    }

    private function quote(ContractorTender $tender, Party $party, string $rate, array $offer = []): ContractorBid
    {
        $line = $tender->items()->value('id');

        return $this->service->addBid($tender, ['party_id' => $party->id, 'rates' => [$line => $rate], ...$offer], $this->owner);
    }

    private function assertChosen(ContractorBid $bid, string $html): void
    {
        $this->assertMatchesRegularExpression('/<option value="'.$bid->id.'"\s+selected/', $html);
        $this->assertSame(1, preg_match_all('/<option value="\d+"\s+selected/', $html));
    }

    public function test_a_line_with_no_quantity_cannot_start_a_tender_that_could_never_take_a_quote(): void
    {
        $this->item->update(['qty' => '0.000']);

        $this->post(route('boq.tenders.store', $this->item), ['title' => 'Rate only'])
            ->assertSessionHasErrors('boq_item_ids');
        $this->assertSame(0, ContractorTender::count());

        $this->get(route('boq.tenders.show', $this->item))
            ->assertOk()
            ->assertSee(__('This line has no quantity yet. Enter its quantity in the BOQ before asking contractors for quotes.'))
            ->assertDontSee(__('New tender for this line'));
    }

    public function test_a_tender_mixing_an_empty_line_with_a_measured_one_still_takes_quotes(): void
    {
        $empty = $this->makeBoqItem($this->project, '0.000', '450.00');
        $tender = $this->service->create($this->project, ['title' => 'Mixed', 'boq_item_ids' => [$this->item->id, $empty->id]], $this->owner);
        $lines = $tender->items()->pluck('id', 'boq_item_id');

        $bid = $this->service->addBid($tender, ['party_id' => $this->contractorNamed('Shree Tiles')->id, 'rates' => [$lines[$this->item->id] => '950', $lines[$empty->id] => '420']], $this->owner);

        $this->assertSame('95000.00', (string) $bid->quoted_amount);
    }

    public function test_a_replaced_quote_link_chooses_that_contractors_newer_quote_not_the_lowest(): void
    {
        $tender = $this->tender();
        $dear = $this->contractorNamed('Dear Builders');
        $cheap = $this->contractorNamed('Cheap Builders');
        $old = $this->quote($tender, $dear, '1000');
        $this->quote($tender, $cheap, '900');
        $newer = $this->quote($tender, $dear, '950');

        $page = $this->get(route('contractor-control.tenders.show', ['tender' => $tender, 'bid' => $old->id]))->assertOk();

        $this->assertChosen($newer, $page->getContent());
        $page->assertSee(__('The quote you chose has been replaced by a newer one from the same contractor, so the newer one is chosen.'));
    }

    public function test_a_quote_link_from_another_tender_chooses_nothing(): void
    {
        $tender = $this->tender();
        $other = $this->tender();
        $this->quote($tender, $this->contractorNamed('Cheap Builders'), '900');
        $stranger = $this->quote($other, $this->contractorNamed('Far Builders'), '800');

        $page = $this->get(route('contractor-control.tenders.show', ['tender' => $tender, 'bid' => $stranger->id]))->assertOk();

        $page->assertSee('<option value="" selected disabled>'.__('Choose a quotation').'</option>', false);
        $this->assertDoesNotMatchRegularExpression('/<option value="\d+"\s+selected/', $page->getContent());
        $page->assertSee(__('The quote you chose is not in this tender. Choose a quotation.'));
    }

    public function test_with_no_quote_asked_for_the_lowest_starts_chosen(): void
    {
        $tender = $this->tender();
        $this->quote($tender, $this->contractorNamed('Dear Builders'), '1000');
        $cheap = $this->quote($tender, $this->contractorNamed('Cheap Builders'), '900');

        $page = $this->get(route('contractor-control.tenders.show', $tender))->assertOk();

        $this->assertChosen($cheap, $page->getContent());
        $page->assertDontSee(__('Choose a quotation'));
    }

    public function test_typing_a_contractor_name_again_finds_the_one_already_there_whatever_its_letters(): void
    {
        foreach (['Élan Interiors', 'Kalyan Tiles', 'श्री बिल्डर्स'] as $name) {
            $first = $this->service->addContractor($this->organization->id, ['name' => $name]);
            $again = $this->service->addContractor($this->organization->id, ['name' => $name]);

            $this->assertSame($first->id, $again->id, $name);
        }

        $this->assertSame(
            Party::where('name', 'Kalyan Tiles')->value('id'),
            $this->service->addContractor($this->organization->id, ['name' => 'KALYAN tiles'])->id,
        );
        $this->assertSame(3, Party::where('type', 'subcontractor')->count());
    }

    public function test_a_quote_cannot_run_out_before_it_was_received(): void
    {
        $tender = $this->tender();
        $party = $this->contractorNamed('Shree Tiles');
        $line = $tender->items()->value('id');
        $url = route('contractor-control.tenders.bids.store', $tender);

        $this->post($url, ['party_id' => $party->id, 'rates' => [$line => '900'], 'received_on' => '2026-11-05', 'valid_until' => '2026-11-04'])
            ->assertSessionHasErrors('valid_until');
        $this->post($url, ['party_id' => $party->id, 'rates' => [$line => '900'], 'valid_until' => today()->subDay()->toDateString()])
            ->assertSessionHasErrors('valid_until');
        $this->assertSame(0, ContractorBid::count());

        $this->post($url, ['party_id' => $party->id, 'rates' => [$line => '900'], 'received_on' => '2026-11-05', 'valid_until' => '2026-11-05'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, ContractorBid::count());
    }

    public function test_the_service_refuses_an_all_empty_tender_too(): void
    {
        $this->item->update(['qty' => '0.000']);

        $this->expectException(ValidationException::class);
        $this->tender();
    }

    public function test_the_boq_list_offers_add_tender_on_each_line_that_can_take_one(): void
    {
        $empty = $this->makeBoqItem($this->project, '0.000', '450.00');

        $page = $this->get(route('boq.index', $this->project))->assertOk();

        $this->assertSame(1, substr_count($page->getContent(), '+ '.__('Add tender')), 'One button, on the line with a quantity.');
        $page->assertSee($this->payloadFor($this->item->id), false)->assertDontSee($this->payloadFor($empty->id), false);

        $site = $this->actingAs($this->supervisor)->get(route('boq.index', $this->project))->assertOk();
        $site->assertDontSee('+ '.__('Add tender'))->assertDontSee($this->payloadFor($this->item->id), false);
    }

    /** How a line's Add tender data appears in the page, as written by @js for Alpine. */
    private function payloadFor(int $itemId): string
    {
        return '\u0022item\u0022:'.$itemId.',';
    }

    public function test_a_tender_added_from_the_boq_list_goes_on_to_the_quotes(): void
    {
        $this->from(route('boq.index', $this->project))
            ->post(route('boq.tenders.store', $this->item), ['form' => 'boq-tender', 'tender_item' => $this->item->id, 'title' => 'Slab concrete', 'client_uuid' => '7d1f6a52-3b8c-4c55-9d5e-0b6b2f1d9a11'])
            ->assertRedirect(route('boq.tenders.show', $this->item));

        $this->assertSame(['Slab concrete'], ContractorTender::pluck('title')->all());
        $this->assertSame([$this->item->id], ContractorTender::first()->items()->pluck('boq_item_id')->all());
    }

    public function test_a_refused_tender_from_the_boq_list_opens_again_on_the_boq_page_for_its_own_line(): void
    {
        $this->from(route('boq.index', $this->project))
            ->post(route('boq.tenders.store', $this->item), ['form' => 'boq-tender', 'tender_item' => $this->item->id, 'title' => '', 'due_on' => 'not a date'])
            ->assertRedirect(route('boq.index', $this->project));

        $this->get(route('boq.index', $this->project))
            ->assertOk()
            ->assertSee('x-init="tender = JSON.parse(', false)
            ->assertSee($this->payloadFor($this->item->id), false)
            ->assertSee(__('validation.required', ['attribute' => 'title']));

        // A line from another project named in the form never opens it.
        $other = $this->makeBoqItem($this->makeProject($this->organization), '10.000', '100.00');
        $this->withSession(['_old_input' => ['form' => 'boq-tender', 'tender_item' => $other->id, 'title' => 'x']])
            ->get(route('boq.index', $this->project))
            ->assertOk()
            ->assertSee('x-init="tender = null"', false);
    }
}
