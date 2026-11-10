<?php

namespace Tests\Feature\ContractorControl;

use App\Http\Presenters\ContractorControlPresenter;
use App\Models\AuditLog;
use App\Models\BoqItem;
use App\Models\ContractorBid;
use App\Models\ContractorTender;
use App\Models\CustomRole;
use App\Models\Party;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Access\TeamAccessService;
use App\Services\ContractorControl\BoqLineTenders;
use App\Services\ContractorControl\ContractorTenderService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * From a line on the BOQ page: the tenders it is in, what each contractor quoted for it,
 * and adding a tender, a contractor and a quotation without going anywhere else.
 */
class BoqLineTendersTest extends TestCase
{
    use BuildsContractorControl, RefreshDatabase;

    /** Everything about a price. None of it may reach someone without money.view, at any depth. */
    private const MONEY_KEYS = [
        'boq_rate', 'boq_amount', 'lowest_rate', 'lowest_contractor', 'rate', 'amount', 'diff', 'diff_amount', 'diff_pct',
        'lowest', 'payment_terms', 'remarks', 'has_quotation', 'quotation_name',
    ];

    private ContractorTenderService $service;

    private BoqLineTenders $lines;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake();
        $this->site();
        $this->service = app(ContractorTenderService::class);
        $this->lines = app(BoqLineTenders::class);
    }

    /** @param  list<BoqItem>  $items */
    private function tender(array $items = [], string $title = 'Flooring'): ContractorTender
    {
        $ids = array_map(fn (BoqItem $item) => $item->id, $items ?: [$this->item]);

        return $this->service->create($this->project, ['title' => $title, 'boq_item_ids' => $ids], $this->owner);
    }

    /** @param  array<int, string>|string  $rates  BOQ item id => rate, or one rate for the first BOQ line. */
    private function quote(ContractorTender $tender, Party|string $contractor, array|string $rates, array $offer = []): ContractorBid
    {
        $party = is_string($contractor) ? $this->contractorNamed($contractor) : $contractor;
        $lines = $tender->items()->pluck('id', 'boq_item_id');
        $rates = is_array($rates) ? $rates : [$this->item->id => $rates];

        return $this->service->addBid($tender, [
            'party_id' => $party->id,
            'rates' => collect($rates)->mapWithKeys(fn (string $rate, int $itemId) => [$lines[$itemId] => $rate])->all(),
            ...$offer,
        ], $this->owner);
    }

    /** The tender line that holds a BOQ item, which is what a quote's rates are keyed by. */
    private function lineId(ContractorTender $tender, ?BoqItem $item = null): int
    {
        return $tender->items()->where('boq_item_id', ($item ?? $this->item)->id)->value('id');
    }

    private function lineUrl(?BoqItem $item = null): string
    {
        return route('boq.tenders.show', $item ?? $this->item);
    }

    private function limitedTo(int $projectId, string $role = 'pm'): User
    {
        $user = $this->member($this->organization, $role);
        app(TeamAccessService::class)->applyProjects($user, true, [$projectId]);

        return $user->refresh();
    }

    /** @return list<string> Every key in the data, at any depth. */
    private function keysIn(array $data): array
    {
        $keys = [];

        foreach ($data as $key => $value) {
            $keys[] = (string) $key;

            if (is_array($value)) {
                $keys = [...$keys, ...$this->keysIn($value)];
            }
        }

        return array_values(array_unique($keys));
    }

    private function assertNoMoneyKeys(array $data): void
    {
        $this->assertSame([], array_values(array_intersect(self::MONEY_KEYS, $this->keysIn($data))));
    }

    /** One tender on the first line with a dearer and a cheaper quote, the cheaper one with its terms. */
    private function flooring(): ContractorTender
    {
        $tender = $this->tender();
        $this->quote($tender, 'Contractor A', '1100');
        $this->quote($tender, 'Contractor B', '900', [
            'received_on' => '2026-11-04', 'valid_until' => '2026-12-31', 'includes_material' => '1', 'completion_days' => '45',
            'payment_terms' => '30 days after the bill', 'remarks' => 'Tiles from Morbi',
        ]);

        return $tender;
    }

    // The sums

    public function test_each_quote_is_set_against_the_boq_rate_in_rupees_and_percent(): void
    {
        $tender = $this->tender();
        $this->quote($tender, 'Contractor A', '1100');
        $this->quote($tender, 'Contractor B', '900');
        $this->quote($tender, 'Contractor C', '1950');

        $line = $this->lines->forLine($this->item);
        $row = $line['tenders'][0];
        $figures = fn (string $contractor) => Arr::only(collect($row['quotes'])->firstWhere('contractor', $contractor), ['rate', 'amount', 'diff', 'diff_amount', 'diff_pct', 'lowest']);

        $this->assertSame(['Contractor B', 'Contractor A', 'Contractor C'], array_column($row['quotes'], 'contractor'), 'The cheapest comes first.');
        $this->assertSame(['100.000', '1000.00', '100000.00', '900.00'], [$row['qty'], $row['boq_rate'], $row['boq_amount'], $row['lowest_rate']]);

        $this->assertSame(['rate' => '1100.00', 'amount' => '110000.00', 'diff' => '100.00', 'diff_amount' => '10000.00', 'diff_pct' => '10.00', 'lowest' => false], $figures('Contractor A'));
        $this->assertSame(['rate' => '900.00', 'amount' => '90000.00', 'diff' => '-100.00', 'diff_amount' => '-10000.00', 'diff_pct' => '-10.00', 'lowest' => true], $figures('Contractor B'));
        $this->assertSame(['rate' => '1950.00', 'amount' => '195000.00', 'diff' => '950.00', 'diff_amount' => '95000.00', 'diff_pct' => '95.00', 'lowest' => false], $figures('Contractor C'));

        $this->assertSame(['tenders' => 1, 'open' => 1, 'quotes' => 3, 'lowest_rate' => '900.00', 'lowest_contractor' => 'Contractor B'], $line['summary']);
    }

    /** 20.10 on 2000.00 is exactly 1.005%, which only rounds to 1.01 when no float is involved. */
    public function test_the_difference_is_worked_out_in_exact_decimals(): void
    {
        $item = $this->makeBoqItem($this->project, '3.333', '2000.00');
        $dearer = $this->tender([$item], 'Dearer');
        $this->quote($dearer, 'Contractor A', [$item->id => '2020.10']);
        $cheaper = $this->tender([$item], 'Cheaper');
        $this->quote($cheaper, 'Contractor B', [$item->id => '1979.90']);

        [$low, $high] = $this->lines->forLine($item)['tenders'];

        $this->assertSame(['6666.00', '6732.99', '20.10', '1.01', '66.99'], [$high['boq_amount'], $high['quotes'][0]['amount'], $high['quotes'][0]['diff'], $high['quotes'][0]['diff_pct'], $high['quotes'][0]['diff_amount']]);
        $this->assertSame(['6599.01', '-20.10', '-1.01', '-66.99'], [$low['quotes'][0]['amount'], $low['quotes'][0]['diff'], $low['quotes'][0]['diff_pct'], $low['quotes'][0]['diff_amount']]);
    }

    public function test_a_line_with_no_boq_rate_has_a_rupee_difference_and_no_percent(): void
    {
        $item = $this->makeBoqItem($this->project, '10.000', '0.00');
        $this->quote($this->tender([$item]), 'Contractor A', [$item->id => '450']);

        $quote = $this->lines->forLine($item)['tenders'][0]['quotes'][0];

        $this->assertSame('450.00', $quote['diff']);
        $this->assertNull($quote['diff_pct']);
    }

    public function test_a_replaced_quote_is_kept_on_the_line_but_no_longer_counted(): void
    {
        $tender = $this->tender();
        $party = $this->contractorNamed('Reviser');
        $old = $this->quote($tender, $party, '950');
        $new = $this->quote($tender, $party, '980');

        $line = $this->lines->forLine($this->item);
        $quotes = $line['tenders'][0]['quotes'];

        $this->assertSame([$new->id, $old->id], array_map(fn (array $quote) => $quote['bid']->id, $quotes));
        $this->assertSame([true, false], array_column($quotes, 'current'));
        $this->assertSame([true, false], array_column($quotes, 'lowest'));
        $this->assertSame(1, $line['summary']['quotes']);
        $this->assertSame('980.00', $line['summary']['lowest_rate']);
        $this->assertSame(['tenders' => 1, 'quotes' => 1, 'lowest_rate' => '980.00'], $this->lines->markers($this->project)[$this->item->id]);
    }

    public function test_two_equal_lowest_rates_are_both_marked(): void
    {
        $tender = $this->tender();
        $this->quote($tender, 'Contractor A', '900');
        $this->quote($tender, 'Contractor B', '900.00');
        $this->quote($tender, 'Contractor C', '901');

        $this->assertSame([true, true, false], array_column($this->lines->forLine($this->item)['tenders'][0]['quotes'], 'lowest'));
    }

    public function test_quotes_stay_compared_with_the_boq_rate_the_tender_was_made_at(): void
    {
        $this->quote($this->tender(), 'Contractor A', '900');
        $this->item->update(['rate' => '2000.00']);

        $shown = ContractorControlPresenter::lineTenders($this->lines->forLine($this->item->refresh()), true);

        $this->assertSame('2000.00', $shown['boq_rate']);
        $this->assertSame('1000.00', $shown['tenders'][0]['boq_rate']);
        $this->assertSame(['-100.00', '-10.00'], [$shown['tenders'][0]['quotes'][0]['diff'], $shown['tenders'][0]['quotes'][0]['diff_pct']]);
    }

    // The marker on the BOQ table

    /** @return array{0: BoqItem, 1: BoqItem, 2: BoqItem} A line in two live tenders and a cancelled one, a line in one, and a line in none. */
    private function markedBoq(): array
    {
        $x = $this->item;
        $y = $this->makeBoqItem($this->project, '50.000', '200.00');
        $z = $this->makeBoqItem($this->project);

        $first = $this->tender([$x]);
        $this->quote($first, 'Contractor A', '1100');
        $reviser = $this->contractorNamed('Contractor B');
        $this->quote($first, $reviser, '900');
        $this->quote($first, $reviser, '880');

        $this->quote($this->tender([$x, $y], 'Finishes'), 'Contractor C', [$x->id => '870', $y->id => '180']);

        $dropped = $this->tender([$x], 'Dropped');
        $this->quote($dropped, 'Contractor D', '500');
        $this->service->cancel($dropped);

        return [$x, $y, $z];
    }

    public function test_the_marker_counts_tenders_and_current_quotes_and_finds_the_lowest_rate(): void
    {
        [$x, $y, $z] = $this->markedBoq();

        $markers = $this->lines->markers($this->project);

        $this->assertSame(['tenders' => 2, 'quotes' => 3, 'lowest_rate' => '870.00'], $markers[$x->id], 'The cancelled tender and the replaced quote are left out.');
        $this->assertSame(['tenders' => 1, 'quotes' => 1, 'lowest_rate' => '180.00'], $markers[$y->id]);
        $this->assertArrayNotHasKey($z->id, $markers);

        // The line's own page counts the same way, and still lists the cancelled tender.
        $line = $this->lines->forLine($x);
        $this->assertSame(['tenders' => 2, 'open' => 2, 'quotes' => 3, 'lowest_rate' => '870.00', 'lowest_contractor' => 'Contractor C'], $line['summary']);
        $this->assertCount(3, $line['tenders']);
    }

    public function test_the_boq_table_shows_the_marker_with_the_lowest_rate_to_someone_who_sees_money(): void
    {
        [$x, $y, $z] = $this->markedBoq();

        $response = $this->actingAs($this->manager)->get(route('boq.index', $this->project))->assertOk();

        $this->assertSame(['tenders' => 2, 'quotes' => 3, 'lowest_rate' => '870.00'], $response->viewData('tenders')[$x->id]);
        $response->assertSee('2 tenders')->assertSee('3 quotes')->assertSee('Lowest ₹870.00')
            ->assertSee('1 tender')->assertSee('1 quote')->assertSee('Lowest ₹180.00');

        // Every line has the action, so a tender can be started from one that has none yet.
        foreach ([$x, $y, $z] as $item) {
            $response->assertSee(route('boq.tenders.show', $item), false);
        }
    }

    public function test_site_staff_see_the_marker_with_no_rate(): void
    {
        [$x] = $this->markedBoq();

        foreach (['engineer', 'viewer'] as $role) {
            $response = $this->actingAs($this->member($this->organization, $role))->get(route('boq.index', $this->project))->assertOk();

            $this->assertSame(['tenders' => 2, 'quotes' => 3], $response->viewData('tenders')[$x->id], $role);
            $response->assertSee('2 tenders')->assertSee('3 quotes')->assertSee(route('boq.tenders.show', $x), false)
                ->assertDontSee('Lowest')->assertDontSee('870.00')->assertDontSee('₹');
        }
    }

    public function test_someone_who_cannot_see_tenders_gets_no_marker(): void
    {
        [$x] = $this->markedBoq();

        $response = $this->actingAs($this->member($this->organization, 'store'))->get(route('boq.index', $this->project))->assertOk();

        $this->assertNull($response->viewData('tenders'));
        $response->assertDontSee(route('boq.tenders.show', $x), false)->assertDontSee('3 quotes');
    }

    public function test_a_plan_without_the_module_has_no_marker_and_no_line_page(): void
    {
        $free = $this->tenant('Small', 'free');
        $owner = $this->member($free, 'owner');
        $this->actingAs($owner);
        $project = $this->makeProject($free);
        $item = $this->makeBoqItem($project);

        $this->assertNull($this->get(route('boq.index', $project))->assertOk()->viewData('tenders'));
        $this->get(route('boq.tenders.show', $item))->assertForbidden();
        $this->post(route('boq.tenders.store', $item), ['title' => 'X'])->assertForbidden();
        $this->postJson(route('contractor-control.contractors.store'), ['name' => 'X'])->assertForbidden();
    }

    // The line's page

    public function test_the_line_page_lists_only_the_tenders_that_hold_that_line(): void
    {
        $other = $this->makeBoqItem($this->project, '50.000', '200.00');
        $flooring = $this->tender([$this->item], 'Flooring');
        $painting = $this->tender([$other], 'Painting');
        $finishes = $this->tender([$this->item, $other], 'Finishes');

        $response = $this->actingAs($this->manager)->get($this->lineUrl())->assertOk();
        $response->assertSee('Flooring')->assertSee('Finishes')->assertDontSee('Painting');
        $this->assertSame([$finishes->id, $flooring->id], array_column($response->viewData('line')['tenders'], 'id'), 'Newest first.');

        $response = $this->actingAs($this->manager)->get($this->lineUrl($other))->assertOk();
        $response->assertSee('Painting')->assertSee('Finishes')->assertDontSee('Flooring');
        $this->assertSame([$finishes->id, $painting->id], array_column($response->viewData('line')['tenders'], 'id'));
    }

    public function test_the_manager_sees_each_rate_its_difference_and_what_the_contractor_offers(): void
    {
        $this->flooring();

        $this->actingAs($this->manager)->get($this->lineUrl())->assertOk()
            ->assertSee('Open for quotes')->assertSee('2 quotes')->assertSee('1 still open')
            ->assertSee('₹900.00')->assertSee('₹90,000.00')->assertSee('−₹100.00')->assertSee('−10%')->assertSee('−₹10,000.00')
            ->assertSee('₹1,100.00')->assertSee('+₹100.00')->assertSee('+10%')
            ->assertSee('Lowest rate')->assertSee('Lowest')
            ->assertSee('Received 04 Nov 2026')->assertSee('Valid till 31 Dec 2026')->assertSee('With material')->assertSee('Finish in 45 days')
            ->assertSee('30 days after the bill')->assertSee('Tiles from Morbi')
            ->assertSee('Add a quote')->assertSee('Select this contractor')->assertSee('New tender');
    }

    public function test_site_staff_see_that_a_tender_exists_and_who_quoted_but_no_price(): void
    {
        $this->flooring();

        foreach (['engineer', 'viewer'] as $role) {
            $response = $this->actingAs($this->member($this->organization, $role))->get($this->lineUrl())->assertOk();

            $response->assertSee('Flooring')->assertSee('Open for quotes')->assertSee('2 quotes')
                ->assertSee('Contractor A')->assertSee('Contractor B')
                ->assertSee('Received 04 Nov 2026')->assertSee('With material')->assertSee('Finish in 45 days')
                ->assertSee('Your role sees who quoted, not the prices.');

            $response->assertDontSee('₹')->assertDontSee('900.00')->assertDontSee('90,000')->assertDontSee('1,100')
                ->assertDontSee('Lowest')->assertDontSee('30 days after the bill')->assertDontSee('Tiles from Morbi')
                ->assertDontSee('Add a quote')->assertDontSee('Select this contractor')->assertDontSee('Open the quotation')->assertDontSee('New tender')
                ->assertDontSee('contractorPopup', false);

            $line = $response->viewData('line');
            $this->assertNoMoneyKeys($line);
            $this->assertSame(['Contractor A', 'Contractor B'], array_column($line['tenders'][0]['quotes'], 'contractor'), 'In the order received, which says nothing about who is cheaper.');
        }
    }

    public function test_accounts_see_the_prices_but_cannot_add_or_choose(): void
    {
        $this->flooring();

        $this->actingAs($this->member($this->organization, 'accounts'))->get($this->lineUrl())->assertOk()
            ->assertSee('₹900.00')->assertSee('Lowest')
            ->assertDontSee('Add a quote')->assertDontSee('Select this contractor')->assertDontSee('New tender for this line');
    }

    public function test_an_estimator_can_add_quotes_here_but_is_not_offered_the_award(): void
    {
        $this->flooring();

        $this->actingAs($this->member($this->organization, 'qs'))->get($this->lineUrl())->assertOk()
            ->assertSee('Add a quote')->assertSee('Add contractor')->assertSee('New tender for this line')
            ->assertDontSee('Select this contractor');
    }

    /** Running tenders means typing rates, so without money.view the permission alone opens nothing. */
    public function test_a_role_that_runs_tenders_but_sees_no_money_gets_names_only(): void
    {
        $tender = $this->flooring();
        $role = CustomRole::create(['organization_id' => $this->organization->id, 'name' => 'Tender clerk', 'permissions' => ['project.view', 'boq.view', 'contractor.view', 'contractor.tender', 'contractor.award']]);
        $clerk = $this->member($this->organization, 'viewer');
        $clerk->forceFill(['custom_role_id' => $role->id])->save();
        $clerk->refresh();

        $response = $this->actingAs($clerk)->get($this->lineUrl())->assertOk();
        $response->assertSee('Contractor B')->assertDontSee('₹')->assertDontSee('Add a quote')->assertDontSee('Select this contractor')->assertDontSee('New tender');
        $this->assertNoMoneyKeys($response->viewData('line'));

        $this->actingAs($clerk)->post(route('boq.tenders.store', $this->item), ['title' => 'Sneaky'])->assertForbidden();
        $this->actingAs($clerk)->post(route('contractor-control.tenders.bids.store', $tender), ['party_id' => $this->contractorNamed('X')->id, 'rates' => [$this->lineId($tender) => '1']])->assertForbidden();
        $this->actingAs($clerk)->postJson(route('contractor-control.contractors.store'), ['name' => 'Sneaky'])->assertForbidden();
        $this->assertSame(1, ContractorTender::count());
    }

    public function test_a_cancelled_tender_stays_on_the_line_but_takes_nothing_more(): void
    {
        $tender = $this->flooring();
        $this->service->cancel($tender);

        $response = $this->actingAs($this->manager)->get($this->lineUrl())->assertOk();

        $response->assertSee('Flooring')->assertSee('Cancelled')->assertSee('Contractor B')->assertSee('₹900.00')
            ->assertDontSee('Add a quote')->assertDontSee('Select this contractor')
            // Nothing live is left, so the counts are empty and a new tender is offered.
            ->assertSee('starting: true', false);
        $line = $response->viewData('line');
        $this->assertSame([0, 0, 0, null], [$line['tender_count'], $line['open_tender_count'], $line['quote_count'], $line['lowest_rate']]);
        $this->assertArrayNotHasKey($this->item->id, $this->lines->markers($this->project));
    }

    public function test_store_staff_cannot_open_a_lines_tenders(): void
    {
        $this->flooring();
        $store = $this->member($this->organization, 'store');

        $this->actingAs($store)->get($this->lineUrl())->assertForbidden();

        Sanctum::actingAs($store);
        $this->getJson('/api/v1/boq-items/'.$this->item->id.'/tenders')->assertForbidden();
    }

    public function test_a_line_with_no_tender_offers_a_new_one_straight_away(): void
    {
        $this->actingAs($this->manager)->get($this->lineUrl())->assertOk()
            ->assertSee('No tenders yet')->assertSee('New tender for this line')
            // Nothing to count yet, so the form comes straight after the line itself.
            ->assertDontSee('Lowest rate')->assertSee('starting: true', false)
            // The title starts as the line's own description.
            ->assertSee('value="RCC M25 in slab"', false);

        $this->actingAs($this->supervisor)->get($this->lineUrl())->assertOk()
            ->assertSee('No tenders yet')->assertDontSee('New tender for this line');
    }

    public function test_the_screen_reads_in_hindi_and_marathi(): void
    {
        $this->flooring();

        $this->manager->update(['locale' => 'hi']);
        $this->actingAs($this->manager)->get($this->lineUrl())->assertOk()
            ->assertSee('इस आइटम के टेंडर')->assertSee('कोटेशन के लिए खुला')->assertSee('सामग्री सहित')->assertSee('45 दिन में पूरा')->assertSee('यह ठेकेदार चुनें');
        $this->actingAs($this->manager)->get(route('boq.index', $this->project))->assertOk()->assertSee('सबसे कम ₹900.00');

        $this->manager->update(['locale' => 'mr']);
        $this->actingAs($this->manager)->get($this->lineUrl())->assertOk()
            ->assertSee('या आयटमचे टेंडर')->assertSee('कोटेशनसाठी खुला')->assertSee('सामग्रीसह')->assertSee('45 दिवसांत पूर्ण')->assertSee('हा ठेकेदार निवडा');
        $this->actingAs($this->manager)->get(route('boq.index', $this->project))->assertOk()->assertSee('सर्वात कमी ₹900.00');
    }

    // Starting a tender from the line

    public function test_a_tender_is_started_from_the_line_with_that_line_already_in_it(): void
    {
        $other = $this->makeBoqItem($this->project);
        $form = ['title' => 'Slab concrete', 'due_on' => '2026-12-15', 'scope' => 'With pump', 'form' => 'tender', 'client_uuid' => (string) Str::uuid()];

        // A list of lines sent along is ignored: this address starts a tender for its own line.
        $this->actingAs($this->manager)->post(route('boq.tenders.store', $this->item), [...$form, 'boq_item_ids' => [$other->id]])
            ->assertRedirect($this->lineUrl())->assertSessionHas('success');

        $tender = ContractorTender::sole();
        $this->assertSame(['Slab concrete', 'OPEN', '2026-12-15', $this->project->id, $this->manager->id], [$tender->title, $tender->status, $tender->due_on->toDateString(), $tender->project_id, $tender->created_by]);
        $this->assertSame([[$this->item->id, '100.000', '1000.00']], $tender->items->map(fn ($line) => [$line->boq_item_id, $line->qty, $line->boq_rate])->all());
        $this->assertTrue(AuditLog::where('auditable_type', $tender->getMorphClass())->where('auditable_id', $tender->id)->where('action', 'created')->where('user_id', $this->manager->id)->exists());

        // Sent twice, for instance on a slow connection: still one tender.
        $this->actingAs($this->manager)->post(route('boq.tenders.store', $this->item), $form)->assertRedirect($this->lineUrl());
        $this->assertSame(1, ContractorTender::count());

        // Back on the line, the new tender is waiting for its first quote with the form open.
        $this->actingAs($this->manager)->get($this->lineUrl())->assertOk()
            ->assertSee('Slab concrete')->assertSee('No quotes yet')->assertSee('quoting: '.$tender->id, false);
    }

    public function test_a_tender_needs_a_title_and_the_form_opens_again(): void
    {
        $this->quote($this->tender(), 'Contractor A', '900');

        $this->actingAs($this->manager)->from($this->lineUrl())->followingRedirects()
            ->post(route('boq.tenders.store', $this->item), ['title' => '', 'scope' => 'Typed already', 'form' => 'tender'])
            ->assertOk()->assertSee('starting: true', false)->assertSee('Typed already');

        $this->assertSame(1, ContractorTender::count());
    }

    public function test_only_people_who_run_tenders_can_start_one(): void
    {
        foreach (['engineer', 'viewer', 'store', 'accounts'] as $role) {
            $this->actingAs($this->member($this->organization, $role))->post(route('boq.tenders.store', $this->item), ['title' => 'Sneaky'])->assertForbidden();
        }

        $this->assertSame(0, ContractorTender::count());

        $this->actingAs($this->member($this->organization, 'qs'))->post(route('boq.tenders.store', $this->item), ['title' => 'By the estimator'])->assertRedirect($this->lineUrl());
        $this->assertSame(1, ContractorTender::count());
    }

    public function test_the_full_tender_form_opens_with_the_line_ticked(): void
    {
        $other = $this->makeBoqItem($this->project);
        $elsewhere = $this->makeBoqItem($this->makeProject($this->organization));

        $response = $this->actingAs($this->manager)->get(route('contractor-control.tenders.create', [$this->project, 'item' => $this->item->id]))->assertOk();
        $this->assertSame([$this->item->id], $response->viewData('ticked'));
        $response->assertSee('value="'.$this->item->id.'" class="mt-1" checked', false)->assertDontSee('value="'.$other->id.'" class="mt-1" checked', false);

        foreach ([null, $elsewhere->id, 'abc'] as $item) {
            $this->assertSame([], $this->actingAs($this->manager)->get(route('contractor-control.tenders.create', [$this->project, 'item' => $item]))->assertOk()->viewData('ticked'));
        }
    }

    // Adding a contractor in the popup

    public function test_a_contractor_is_added_from_the_popup_without_leaving_the_page(): void
    {
        $response = $this->actingAs($this->manager)->postJson(route('contractor-control.contractors.store'), [
            'name' => '  Shree Tiles  ', 'contact_person' => 'Site office', 'phone' => '0000000001', 'email' => '', 'gstin' => '27aaaaa0000a1z5', 'address' => 'Pune',
        ])->assertCreated()->assertJsonPath('name', 'Shree Tiles')->assertJsonPath('message', null);

        $party = Party::findOrFail($response->json('id'));
        $this->assertSame(['subcontractor', $this->organization->id, 'Shree Tiles', 'Site office', '0000000001', null, '27AAAAA0000A1Z5', 'Pune'], [$party->type, $party->organization_id, $party->name, $party->contact_person, $party->phone, $party->email, $party->gstin, $party->address]);
        $this->assertTrue(AuditLog::where('auditable_type', $party->getMorphClass())->where('auditable_id', $party->id)->where('action', 'created')->exists());

        // The quote forms then offer the new contractor.
        $this->tender();
        $this->actingAs($this->manager)->get($this->lineUrl())->assertOk()->assertSee('Shree Tiles')->assertSee('contractorPopup()', false);
    }

    public function test_a_name_already_on_the_list_comes_back_as_that_contractor(): void
    {
        $existing = $this->contractorNamed('Shree Tiles');
        $count = Party::count();

        $this->actingAs($this->manager)->postJson(route('contractor-control.contractors.store'), ['name' => 'shree tiles', 'phone' => '0000000002'])
            ->assertOk()->assertJsonPath('id', $existing->id)->assertJsonPath('name', 'Shree Tiles')->assertJsonPath('message', 'Shree Tiles is already on your list.');

        $this->assertSame($count, Party::count());
        $this->assertNull($existing->refresh()->phone, 'The contractor already there is not rewritten.');
    }

    public function test_bad_contractor_details_come_back_against_each_field(): void
    {
        $count = Party::count();

        $this->actingAs($this->manager)->postJson(route('contractor-control.contractors.store'), ['name' => '', 'email' => 'not-an-email', 'gstin' => 'SHORT'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'gstin']);

        $this->assertSame($count, Party::count());
    }

    public function test_only_people_who_run_tenders_can_add_a_contractor(): void
    {
        $count = Party::count();

        foreach (['engineer', 'viewer', 'store', 'accounts'] as $role) {
            $this->actingAs($this->member($this->organization, $role))->postJson(route('contractor-control.contractors.store'), ['name' => 'Sneaky'])->assertForbidden();
        }

        $this->assertSame($count, Party::count());

        $this->actingAs($this->member($this->organization, 'qs'))->postJson(route('contractor-control.contractors.store'), ['name' => 'By the estimator'])->assertCreated();
    }

    public function test_a_contractor_added_here_stays_inside_the_company(): void
    {
        $other = $this->tenant('Other Co');
        $theirs = Party::create(['organization_id' => $other->id, 'type' => 'subcontractor', 'name' => 'Shree Tiles']);

        $id = $this->actingAs($this->manager)->postJson(route('contractor-control.contractors.store'), ['name' => 'Shree Tiles'])->assertCreated()->json('id');

        $this->assertNotSame($theirs->id, $id, 'Another company\'s contractor of the same name is not handed over.');
        $this->assertSame($this->organization->id, Party::findOrFail($id)->organization_id);

        $this->actingAs($this->member($other, 'owner'));
        $this->assertSame([$theirs->id], Party::pluck('id')->all(), 'The other company still has only its own.');
    }

    // Adding a quotation from the line

    public function test_a_new_contractor_and_their_quotation_are_added_from_the_line(): void
    {
        $tender = $this->tender();
        $contractor = $this->actingAs($this->manager)->postJson(route('contractor-control.contractors.store'), ['name' => 'Shree Tiles'])->json('id');

        $this->actingAs($this->manager)->from($this->lineUrl())->post(route('contractor-control.tenders.bids.store', $tender), [
            'form' => 'quote-'.$tender->id,
            'client_uuid' => (string) Str::uuid(),
            'party_id' => $contractor,
            'rates' => [$this->lineId($tender) => '925.50'],
            // Both fixed: with "received today", the run after 31 January 2027 would see a quote valid until before it arrived.
            'received_on' => '2026-11-05',
            'valid_until' => '2027-01-31',
            'includes_material' => '0',
            'completion_days' => '30',
            'payment_terms' => 'Weekly, on measurement',
            'remarks' => 'Labour rate only',
            'quotation' => UploadedFile::fake()->create('Shree quotation.pdf', 120, 'application/pdf'),
        ])->assertRedirect($this->lineUrl())->assertSessionHas('success');

        $bid = ContractorBid::sole();
        $this->assertSame([$tender->id, $contractor, '92550.00', $this->manager->id], [$bid->contractor_tender_id, $bid->party_id, $bid->quoted_amount, $bid->entered_by]);
        $this->assertSame(['925.50', '92550.00'], [$bid->items[0]->rate, $bid->items[0]->amount]);
        $this->assertSame(['2027-01-31', false, 30, 'Weekly, on measurement', 'Labour rate only', 'Shree quotation.pdf'], [$bid->valid_until->toDateString(), $bid->includes_material, $bid->completion_days, $bid->payment_terms, $bid->remarks, $bid->quotation_name]);
        $this->assertStringStartsWith('org-'.$this->organization->id.'/contractor-quotations/'.$this->project->id.'/', $bid->quotation_path);
        Storage::assertExists($bid->quotation_path);
        $this->assertTrue(AuditLog::where('auditable_type', $bid->getMorphClass())->where('auditable_id', $bid->id)->where('action', 'created')->where('user_id', $this->manager->id)->exists());

        $this->actingAs($this->manager)->get($this->lineUrl())->assertOk()
            ->assertSee('Shree Tiles')->assertSee('₹925.50')->assertSee('−₹74.50')->assertSee('−7.45%')->assertSee('₹92,550.00')
            ->assertSee('Labour only')->assertSee('Finish in 30 days')->assertSee('Valid till 31 Jan 2027')->assertSee('Weekly, on measurement')
            ->assertSee(route('contractor-control.tenders.bids.quotation', $bid), false);

        $this->assertSame(['tenders' => 1, 'quotes' => 1, 'lowest_rate' => '925.50'], $this->lines->markers($this->project)[$this->item->id]);
    }

    public function test_a_quote_for_a_tender_with_more_lines_needs_a_rate_for_each(): void
    {
        $other = $this->makeBoqItem($this->project, '50.000', '200.00');
        $tender = $this->tender([$this->item, $other], 'Finishes');
        $party = $this->contractorNamed('Shree Tiles');
        $form = ['form' => 'quote-'.$tender->id, 'party_id' => $party->id, 'payment_terms' => 'Typed already'];

        // Only this line's rate: refused, and the same form opens again with what was typed.
        $this->actingAs($this->manager)->from($this->lineUrl())->followingRedirects()
            ->post(route('contractor-control.tenders.bids.store', $tender), [...$form, 'rates' => [$this->lineId($tender) => '950']])
            ->assertOk()->assertSee('Enter a rate for every item')->assertSee('quoting: '.$tender->id, false)
            ->assertSee('value="950"', false)->assertSee('Typed already')->assertSee('Rates for the other lines in this tender');
        $this->assertSame(0, ContractorBid::count());

        $this->actingAs($this->manager)->from($this->lineUrl())
            ->post(route('contractor-control.tenders.bids.store', $tender), [...$form, 'rates' => [$this->lineId($tender) => '950', $this->lineId($tender, $other) => '180']])
            ->assertRedirect($this->lineUrl())->assertSessionHasNoErrors();

        $this->assertSame('104000.00', ContractorBid::sole()->quoted_amount);
        $this->actingAs($this->manager)->get($this->lineUrl($other))->assertOk()->assertSee('₹180.00')->assertSee('−₹20.00');
    }

    public function test_a_refused_quotation_leaves_no_file_behind(): void
    {
        $tender = $this->tender();
        $party = $this->contractorNamed('Shree Tiles');
        $url = route('contractor-control.tenders.bids.store', $tender);

        // Not a photo or a PDF.
        $this->actingAs($this->manager)->from($this->lineUrl())->post($url, ['party_id' => $party->id, 'rates' => [$this->lineId($tender) => '900'], 'quotation' => UploadedFile::fake()->create('rates.exe', 10, 'application/octet-stream')])
            ->assertSessionHasErrors('quotation');

        // A good file with no price.
        $this->actingAs($this->manager)->from($this->lineUrl())->post($url, ['party_id' => $party->id, 'quotation' => UploadedFile::fake()->create('quotation.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('quoted_amount');

        // A contractor who has gone by the time the quote is saved.
        try {
            $this->service->addBid($tender, ['party_id' => 999999, 'rates' => [$this->lineId($tender) => '900']], $this->manager, UploadedFile::fake()->create('quotation.pdf', 10, 'application/pdf'));
            $this->fail('A quote was saved for a contractor who does not exist.');
        } catch (ModelNotFoundException) {
            // Refused inside the save.
        }

        $this->assertSame(0, ContractorBid::count());
        $this->assertSame([], Storage::allFiles());
    }

    public function test_a_quote_is_not_saved_without_the_file_that_came_with_it(): void
    {
        $tender = $this->tender();
        $party = $this->contractorNamed('Shree Tiles');
        $unsaved = Mockery::mock(UploadedFile::class);
        $unsaved->shouldReceive('store')->once()->andReturn(false);

        try {
            $this->service->addBid($tender, ['party_id' => $party->id, 'rates' => [$this->lineId($tender) => '900']], $this->manager, $unsaved);
            $this->fail('The quote was saved although its file could not be stored.');
        } catch (ValidationException $exception) {
            $this->assertSame(['quotation'], array_keys($exception->errors()));
        }

        $this->assertSame(0, ContractorBid::count());
    }

    public function test_a_quotation_sent_twice_is_saved_once_with_one_file(): void
    {
        $tender = $this->tender();
        $party = $this->contractorNamed('Shree Tiles');
        $uuid = (string) Str::uuid();
        $form = fn () => [
            'client_uuid' => $uuid, 'party_id' => $party->id,
            'rates' => [$this->lineId($tender) => '900'], 'quotation' => UploadedFile::fake()->create('quotation.pdf', 10, 'application/pdf'),
        ];

        $this->actingAs($this->manager)->post(route('contractor-control.tenders.bids.store', $tender), $form())->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('contractor-control.tenders.bids.store', $tender), $form())->assertSessionHasNoErrors();

        $this->assertSame(1, ContractorBid::count());
        $this->assertSame([ContractorBid::sole()->quotation_path], Storage::allFiles());
    }

    public function test_site_staff_cannot_add_a_quotation(): void
    {
        $tender = $this->tender();
        $party = $this->contractorNamed('Shree Tiles');

        foreach (['engineer', 'viewer', 'store', 'accounts'] as $role) {
            $this->actingAs($this->member($this->organization, $role))
                ->post(route('contractor-control.tenders.bids.store', $tender), ['party_id' => $party->id, 'rates' => [$this->lineId($tender) => '900']])
                ->assertForbidden();
        }

        $this->assertSame(0, ContractorBid::count());
    }

    // The attached quotation

    private function quotationOn(ContractorBid $bid): ContractorBid
    {
        Storage::put('org-'.$bid->organization_id.'/contractor-quotations/q-'.$bid->id.'.pdf', 'the quotation');
        $bid->update(['quotation_path' => 'org-'.$bid->organization_id.'/contractor-quotations/q-'.$bid->id.'.pdf', 'quotation_name' => 'quotation.pdf']);

        return $bid;
    }

    public function test_the_quotation_file_opens_only_for_people_who_see_money(): void
    {
        $bid = $this->quotationOn($this->quote($this->tender(), 'Contractor A', '900'));
        $url = route('contractor-control.tenders.bids.quotation', $bid);

        foreach (['owner', 'pm', 'qs', 'accounts'] as $role) {
            $response = $this->actingAs($this->member($this->organization, $role))->get($url)->assertOk();
            $this->assertSame('the quotation', $response->streamedContent(), $role);
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
        }

        foreach (['engineer', 'viewer', 'store'] as $role) {
            $this->actingAs($this->member($this->organization, $role))->get($url)->assertForbidden();
        }
    }

    public function test_the_quotation_file_is_out_of_reach_from_another_company_or_project(): void
    {
        $bid = $this->quotationOn($this->quote($this->tender(), 'Contractor A', '900'));
        $url = route('contractor-control.tenders.bids.quotation', $bid);

        $this->actingAs($this->member($this->tenant('Other Co'), 'owner'))->get($url)->assertNotFound();
        $this->actingAs($this->limitedTo($this->makeProject($this->organization)->id))->get($url)->assertNotFound();
        $this->actingAs($this->limitedTo($this->project->id))->get($url)->assertOk();
    }

    public function test_a_quote_with_no_file_or_a_lost_file_answers_not_found(): void
    {
        $tender = $this->tender();
        $plain = $this->quote($tender, 'Contractor A', '900');
        $lost = $this->quote($tender, 'Contractor B', '950');
        $lost->update(['quotation_path' => 'org-0/contractor-quotations/gone.pdf']);

        $this->actingAs($this->manager)->get(route('contractor-control.tenders.bids.quotation', $plain))->assertNotFound();
        $this->actingAs($this->manager)->get(route('contractor-control.tenders.bids.quotation', $lost))->assertNotFound();
    }

    // Choosing the winner

    public function test_choosing_from_the_line_goes_through_the_tenders_own_award_form(): void
    {
        $tender = $this->tender();
        $dearer = $this->quote($tender, 'Contractor A', '1100');
        $cheaper = $this->quote($tender, 'Contractor B', '900');
        $awardForm = fn (ContractorBid $bid) => route('contractor-control.tenders.show', ['tender' => $tender->id, 'bid' => $bid->id]);

        $this->actingAs($this->manager)->get($this->lineUrl())->assertOk()
            ->assertSee($awardForm($dearer).'#award', false)->assertSee($awardForm($cheaper).'#award', false);

        // The tender's page opens with the quote that was picked, rather than the lowest.
        $this->actingAs($this->manager)->get($awardForm($dearer))->assertOk()
            ->assertSee('id="award"', false)->assertSee('value="'.$dearer->id.'" selected', false)->assertDontSee('value="'.$cheaper->id.'" selected', false);
        $this->actingAs($this->manager)->get(route('contractor-control.tenders.show', $tender))->assertOk()
            ->assertSee('value="'.$cheaper->id.'" selected', false)->assertDontSee('value="'.$dearer->id.'" selected', false);

        $this->actingAs($this->manager)->post(route('contractor-control.tenders.award', $tender), [
            'bid_id' => $dearer->id, 'retention_pct' => '5', 'tds_pct' => '1', 'gst_pct' => '18', 'advance_amount' => '0', 'advance_recovery_pct' => '0',
            'deduction_basis' => 'gross', 'deduction_order' => array_key_first(config('buildos.deduction_orders')),
        ])->assertRedirect();

        $workOrder = WorkOrder::sole();
        $this->assertSame('1100.00', $workOrder->items()->first()->rate);

        // Back on the line: awarded, the chosen quote marked, and nothing more to add or choose.
        $this->actingAs($this->manager)->get($this->lineUrl())->assertOk()
            ->assertSee('Awarded')->assertSee('Selected')->assertSee($workOrder->number)
            ->assertDontSee('Add a quote')->assertDontSee('Select this contractor');
        $this->assertSame(['tenders' => 1, 'open' => 0, 'quotes' => 2, 'lowest_rate' => '900.00', 'lowest_contractor' => 'Contractor B'], $this->lines->forLine($this->item)['summary']);
    }

    public function test_the_tenders_page_shows_what_each_contractor_offers_and_their_quotation(): void
    {
        $tender = $this->flooring();
        $bid = $this->quotationOn(ContractorBid::where('contractor_tender_id', $tender->id)->latest('id')->firstOrFail());

        $this->actingAs($this->manager)->get(route('contractor-control.tenders.show', $tender))->assertOk()
            ->assertSee('Open for quotes')
            ->assertSee('Received 04 Nov 2026')->assertSee('Valid till 31 Dec 2026')->assertSee('With material')->assertSee('Finish in 45 days')
            ->assertSee('30 days after the bill')->assertSee('Tiles from Morbi')
            ->assertSee(route('contractor-control.tenders.bids.quotation', $bid), false)
            ->assertSee('Add contractor')->assertSee('contractorPopup()', false)->assertSee('Quotation file');
    }

    // The app

    public function test_the_api_gives_the_manager_every_figure(): void
    {
        $tender = $this->flooring();
        Sanctum::actingAs($this->manager);

        $response = $this->getJson('/api/v1/boq-items/'.$this->item->id.'/tenders')->assertOk();

        $response->assertJsonPath('data.boq_item_id', $this->item->id)
            ->assertJsonPath('data.boq_rate', '1000.00')->assertJsonPath('data.boq_amount', '100000.00')
            ->assertJsonPath('data.tender_count', 1)->assertJsonPath('data.open_tender_count', 1)->assertJsonPath('data.quote_count', 2)
            ->assertJsonPath('data.lowest_rate', '900.00')->assertJsonPath('data.lowest_contractor', 'Contractor B')
            ->assertJsonPath('data.tenders.0.id', $tender->id)->assertJsonPath('data.tenders.0.number', $tender->number)->assertJsonPath('data.tenders.0.status', 'OPEN')
            ->assertJsonPath('data.tenders.0.boq_rate', '1000.00')->assertJsonPath('data.tenders.0.lowest_rate', '900.00')
            ->assertJsonPath('data.tenders.0.lines.0.boq_item_id', $this->item->id)
            ->assertJsonPath('data.tenders.0.quotes.0.contractor', 'Contractor B')
            ->assertJsonPath('data.tenders.0.quotes.0.rate', '900.00')->assertJsonPath('data.tenders.0.quotes.0.amount', '90000.00')
            ->assertJsonPath('data.tenders.0.quotes.0.diff', '-100.00')->assertJsonPath('data.tenders.0.quotes.0.diff_pct', '-10.00')
            ->assertJsonPath('data.tenders.0.quotes.0.lowest', true)->assertJsonPath('data.tenders.0.quotes.0.received_on', '2026-11-04')
            ->assertJsonPath('data.tenders.0.quotes.0.valid_until', '2026-12-31')->assertJsonPath('data.tenders.0.quotes.0.includes_material', true)
            ->assertJsonPath('data.tenders.0.quotes.0.completion_days', 45)->assertJsonPath('data.tenders.0.quotes.0.payment_terms', '30 days after the bill')
            ->assertJsonPath('data.tenders.0.quotes.0.has_quotation', false);
    }

    public function test_the_api_gives_site_staff_who_quoted_and_no_price(): void
    {
        $this->flooring();

        foreach (['engineer', 'viewer'] as $role) {
            Sanctum::actingAs($this->member($this->organization, $role));

            $response = $this->getJson('/api/v1/boq-items/'.$this->item->id.'/tenders')->assertOk();
            $json = $response->getContent();

            foreach (self::MONEY_KEYS as $key) {
                $this->assertStringNotContainsString('"'.$key.'"', $json, "{$role} received {$key}.");
            }

            foreach (['900.00', '1100.00', '1000.00', '0000.00', '30 days after the bill', 'Tiles from Morbi'] as $figure) {
                $this->assertStringNotContainsString($figure, $json, "{$role} received {$figure}.");
            }

            $response->assertJsonPath('data.tender_count', 1)->assertJsonPath('data.quote_count', 2)
                ->assertJsonPath('data.tenders.0.title', 'Flooring')->assertJsonPath('data.tenders.0.status', 'OPEN')
                ->assertJsonPath('data.tenders.0.quotes.0.contractor', 'Contractor A')->assertJsonPath('data.tenders.0.quotes.1.contractor', 'Contractor B')
                ->assertJsonPath('data.tenders.0.quotes.1.received_on', '2026-11-04')->assertJsonPath('data.tenders.0.quotes.1.includes_material', true);
        }
    }

    // Other companies and other projects

    public function test_another_company_cannot_reach_a_lines_tenders(): void
    {
        $tender = $this->flooring();
        $stranger = $this->member($this->tenant('Other Co'), 'owner');

        $this->actingAs($stranger)->get($this->lineUrl())->assertNotFound();
        $this->actingAs($stranger)->post(route('boq.tenders.store', $this->item), ['title' => 'Planted'])->assertNotFound();
        $this->actingAs($stranger)->post(route('contractor-control.tenders.bids.store', $tender), ['quoted_amount' => '1', 'new_contractor' => 'Planted'])->assertNotFound();

        Sanctum::actingAs($stranger);
        $this->getJson('/api/v1/boq-items/'.$this->item->id.'/tenders')->assertNotFound();

        $this->assertSame(1, ContractorTender::withoutGlobalScopes()->count());
        $this->assertSame(2, ContractorBid::withoutGlobalScopes()->count());
    }

    public function test_a_contractor_from_another_company_cannot_be_given_a_quote(): void
    {
        $tender = $this->tender();
        $outsider = Party::create(['organization_id' => $this->tenant('Other Co')->id, 'type' => 'subcontractor', 'name' => 'Outsider']);

        $this->actingAs($this->manager)->from($this->lineUrl())
            ->post(route('contractor-control.tenders.bids.store', $tender), ['party_id' => $outsider->id, 'rates' => [$this->lineId($tender) => '900']])
            ->assertSessionHasErrors('party_id');

        $this->assertSame(0, ContractorBid::count());
    }

    public function test_someone_limited_to_another_project_cannot_reach_this_lines_tenders(): void
    {
        $this->flooring();
        $elsewhere = $this->limitedTo($this->makeProject($this->organization)->id);

        $this->actingAs($elsewhere)->get($this->lineUrl())->assertNotFound();
        $this->actingAs($elsewhere)->post(route('boq.tenders.store', $this->item), ['title' => 'Planted'])->assertNotFound();
        $this->assertSame([], $this->lines->markers($this->project), 'Nor do the markers count a project they cannot see.');

        // Someone limited to this project sees it all.
        $this->actingAs($this->limitedTo($this->project->id))->get($this->lineUrl())->assertOk()->assertSee('Contractor B')->assertSee('₹900.00');

        Sanctum::actingAs($elsewhere);
        $this->getJson('/api/v1/boq-items/'.$this->item->id.'/tenders')->assertNotFound();
        $this->assertSame(1, ContractorTender::withoutGlobalScopes()->count());
    }

    public function test_the_new_columns_can_be_rolled_back_and_added_again(): void
    {
        $path = 'database/migrations/2027_01_03_100001_add_quotation_details_to_contractor_bids.php';
        $columns = ['valid_until', 'includes_material', 'payment_terms', 'completion_days', 'quotation_path', 'quotation_name'];
        $this->flooring();

        $this->artisan('migrate:rollback', ['--path' => $path])->assertSuccessful();
        $this->assertFalse(Schema::hasColumns('contractor_bids', $columns));
        $this->assertFalse(Schema::hasColumn('contractor_bids', 'valid_until'));
        $this->assertSame(2, ContractorBid::count(), 'The quotes themselves are kept.');

        $this->artisan('migrate', ['--path' => $path])->assertSuccessful();
        $this->assertTrue(Schema::hasColumns('contractor_bids', $columns));
    }
}
