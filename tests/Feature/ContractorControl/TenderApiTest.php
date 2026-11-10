<?php

namespace Tests\Feature\ContractorControl;

use App\Models\AuditLog;
use App\Models\BoqItem;
use App\Models\ContractorBid;
use App\Models\ContractorTender;
use App\Models\Party;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Access\TeamAccessService;
use App\Services\ContractorControl\ContractorTenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The field app does what the web's "Tenders for this BOQ line" page does: see a line's tenders,
 * start one, add contractors and their quotes, compare them and choose one. Each endpoint takes
 * its web twin's permissions, and nobody without money.view receives a price.
 */
class TenderApiTest extends TestCase
{
    use BuildsContractorControl, RefreshDatabase;

    /** Everything about a price, and the free text that may quote one. None of it may reach someone without money.view. */
    private const MONEY_KEYS = [
        'boq_rate', 'boq_amount', 'lowest_rate', 'lowest_contractor', 'lowest_amount', 'rate', 'rates', 'amount', 'diff', 'diff_amount', 'diff_pct',
        'lowest', 'payment_terms', 'remarks', 'has_quotation', 'quotation_name', 'scope', 'award_note',
    ];

    private const ROLES = ['owner', 'pm', 'qs', 'accounts', 'engineer', 'store', 'viewer'];

    private ContractorTenderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->site();
        $this->service = app(ContractorTenderService::class);
    }

    private function as(User|string $who): static
    {
        Sanctum::actingAs(is_string($who) ? $this->member($this->organization, $who) : $who);

        return $this;
    }

    /** @param  list<BoqItem>  $items */
    private function tender(array $items = [], string $title = 'Flooring'): ContractorTender
    {
        $ids = array_map(fn (BoqItem $item) => $item->id, $items ?: [$this->item]);

        return $this->service->create($this->project, ['title' => $title, 'boq_item_ids' => $ids], $this->owner);
    }

    private function quote(ContractorTender $tender, Party|string $contractor, string $rate, array $offer = []): ContractorBid
    {
        $party = is_string($contractor) ? $this->contractorNamed($contractor) : $contractor;

        return $this->service->addBid($tender, ['party_id' => $party->id, 'rates' => [$this->lineId($tender) => $rate], ...$offer], $this->owner);
    }

    private function lineId(ContractorTender $tender): int
    {
        return $tender->items()->where('boq_item_id', $this->item->id)->value('id');
    }

    private function quotationOn(ContractorBid $bid): ContractorBid
    {
        Storage::put('org-'.$bid->organization_id.'/contractor-quotations/q-'.$bid->id.'.pdf', 'the quotation');
        $bid->update(['quotation_path' => 'org-'.$bid->organization_id.'/contractor-quotations/q-'.$bid->id.'.pdf', 'quotation_name' => 'quotation.pdf']);

        return $bid;
    }

    /**
     * Contractor A quoted 1200, then replaced it with 1100. Contractor B quoted 900 with terms and a quotation.
     *
     * @return array{0: ContractorTender, 1: ContractorBid, 2: ContractorBid, 3: ContractorBid} The tender, A's old and current quotes, and B's.
     */
    private function flooring(): array
    {
        $tender = $this->tender();
        $a = $this->contractorNamed('Contractor A');
        $old = $this->quote($tender, $a, '1200');
        $current = $this->quote($tender, $a, '1100');
        $b = $this->quotationOn($this->quote($tender, 'Contractor B', '900', [
            'received_on' => '2026-11-04', 'valid_until' => '2026-12-31', 'includes_material' => '1', 'completion_days' => '45',
            'payment_terms' => '30 days after the bill', 'remarks' => 'Tiles from Morbi',
        ]));

        return [$tender, $old, $current, $b];
    }

    private function awardTerms(ContractorBid $bid): array
    {
        return [
            'bid_id' => $bid->id, 'retention_pct' => '5', 'tds_pct' => '1', 'gst_pct' => '18', 'advance_amount' => '0', 'advance_recovery_pct' => '0',
            'deduction_basis' => 'gross', 'deduction_order' => array_key_first(config('buildos.deduction_orders')),
        ];
    }

    private function limitedTo(int $projectId, string $role = 'pm'): User
    {
        $user = $this->member($this->organization, $role);
        app(TeamAccessService::class)->applyProjects($user, true, [$projectId]);

        return $user->refresh();
    }

    private function boqUrl(): string
    {
        return '/api/v1/projects/'.$this->project->id.'/boq';
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

    /** No price key at any depth, and none of the figures or the free text that carries them. */
    private function assertNoMoney(TestResponse $response, string $who): void
    {
        $this->assertSame([], array_values(array_intersect(self::MONEY_KEYS, $this->keysIn($response->json()))), "{$who} received a money key.");

        foreach (['900.00', '1100.00', '1200.00', '1000.00', '0000.00', '30 days after the bill', 'Tiles from Morbi', 'quotation.pdf', '₹'] as $figure) {
            $this->assertStringNotContainsString($figure, (string) $response->getContent(), "{$who} received {$figure}.");
        }
    }

    private function assertFields(array $expected, array $actual, string $message = ''): void
    {
        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $actual, $message);
            $this->assertSame($value, $actual[$key], trim("{$message} {$key}"));
        }
    }

    // The BOQ list

    public function test_the_boq_gives_each_tendered_line_its_marker_with_the_lowest_rate(): void
    {
        $this->flooring();
        $untendered = $this->makeBoqItem($this->project, '10.000', '50.00');

        $rows = collect($this->as($this->manager)->getJson($this->boqUrl())->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(['tenders' => 1, 'quotes' => 2, 'lowest_rate' => '900.00'], $rows[$this->item->id]['tenders'], 'A replaced quote is not counted.');
        $this->assertArrayHasKey('tenders', $rows[$untendered->id]);
        $this->assertNull($rows[$untendered->id]['tenders'], 'A line with no tender has no marker.');

        // The rest of each row is as the app already reads it.
        $this->assertSame(
            ['id', 'project_id', 'section', 'code', 'description', 'unit', 'qty', 'rate', 'sort', 'measured_qty', 'approved_qty', 'balance_qty', 'updated_at', 'tenders'],
            array_keys($rows[$this->item->id]),
        );
        $this->assertSame(['100.000', '1000.00', '100.000'], [$rows[$this->item->id]['qty'], $rows[$this->item->id]['rate'], $rows[$this->item->id]['balance_qty']]);
    }

    public function test_site_staff_get_the_marker_without_the_rate_and_store_staff_get_none(): void
    {
        $this->flooring();

        foreach (['engineer', 'viewer'] as $role) {
            $row = $this->as($role)->getJson($this->boqUrl())->assertOk()->json('data.0');

            $this->assertSame(['tenders' => 1, 'quotes' => 2], $row['tenders'], $role);
            $this->assertNull($row['rate'], $role);
        }

        // Tenders are not theirs to see, so not even the count reaches them.
        $row = $this->as('store')->getJson($this->boqUrl())->assertOk()->json('data.0');
        $this->assertArrayHasKey('tenders', $row);
        $this->assertNull($row['tenders']);
    }

    public function test_a_plan_without_the_module_has_no_marker_and_no_tender_endpoints(): void
    {
        $free = $this->tenant('Small', 'free');
        $owner = $this->member($free, 'owner');
        Sanctum::actingAs($owner);
        $project = $this->makeProject($free);
        $item = $this->makeBoqItem($project);

        $row = $this->getJson('/api/v1/projects/'.$project->id.'/boq')->assertOk()->json('data.0');
        $this->assertArrayHasKey('tenders', $row);
        $this->assertNull($row['tenders']);

        $this->getJson(route('api.boq.tenders.show', $item))->assertForbidden();
        $this->postJson(route('api.boq.tenders.store', $item), ['title' => 'X'])->assertForbidden();
        $this->getJson(route('api.contractor-control.contractors.index'))->assertForbidden();
        $this->postJson(route('api.contractor-control.contractors.store'), ['name' => 'X'])->assertForbidden();
        $this->assertSame(0, ContractorTender::withoutGlobalScopes()->count());
    }

    // A line's tenders

    /** @return array<string, array{0: string, 1: array{quote: bool, award: bool}}> */
    public static function lineAbilities(): array
    {
        return [
            'owner' => ['owner', ['quote' => true, 'award' => true]],
            'pm' => ['pm', ['quote' => true, 'award' => true]],
            'qs' => ['qs', ['quote' => true, 'award' => false]],
            'accounts' => ['accounts', ['quote' => false, 'award' => false]],
            'engineer' => ['engineer', ['quote' => false, 'award' => false]],
            'viewer' => ['viewer', ['quote' => false, 'award' => false]],
        ];
    }

    #[DataProvider('lineAbilities')]
    public function test_a_line_says_what_this_person_may_do_with_its_tenders(string $role, array $can): void
    {
        $this->flooring();

        $this->as($role)->getJson(route('api.boq.tenders.show', $this->item))->assertOk()
            ->assertJsonPath('data.can', $can)
            ->assertJsonPath('data.no_quantity', false)
            ->assertJsonPath('data.tender_count', 1);
    }

    public function test_a_line_with_no_quantity_says_so_and_cannot_start_a_tender(): void
    {
        $empty = $this->makeBoqItem($this->project, '0.000', '1000.00');

        $this->as($this->manager)->getJson(route('api.boq.tenders.show', $empty))->assertOk()
            ->assertJsonPath('data.no_quantity', true)->assertJsonPath('data.tender_count', 0)->assertJsonPath('data.tenders', []);

        $this->postJson(route('api.boq.tenders.store', $empty), ['title' => 'Nothing to price yet'])
            ->assertUnprocessable()->assertJsonValidationErrors('boq_item_ids');

        $this->assertSame(0, ContractorTender::count());
    }

    public function test_site_staff_see_a_lines_tenders_and_who_quoted_but_no_price(): void
    {
        $this->flooring();

        foreach (['engineer', 'viewer'] as $role) {
            $response = $this->as($role)->getJson(route('api.boq.tenders.show', $this->item))->assertOk();

            $this->assertNoMoney($response, $role);
            $response->assertJsonPath('data.quote_count', 2)->assertJsonPath('data.tenders.0.quotes.2.contractor', 'Contractor B');
        }
    }

    // Starting a tender

    public function test_a_tender_is_started_from_a_line_and_sending_it_again_returns_the_same_one(): void
    {
        $other = $this->makeBoqItem($this->project);
        $form = ['title' => 'Slab concrete', 'scope' => 'With pump', 'due_on' => '2026-12-15', 'client_uuid' => (string) Str::uuid()];

        // A list of lines sent along is ignored: this address starts a tender for its own line.
        $response = $this->as($this->manager)->postJson(route('api.boq.tenders.store', $this->item), [...$form, 'boq_item_ids' => [$other->id]])->assertCreated();

        $tender = ContractorTender::sole();
        $line = $tender->items()->sole();
        $this->assertSame([$this->item->id, '100.000', '1000.00'], [$line->boq_item_id, $line->qty, $line->boq_rate]);
        $this->assertSame([$this->manager->id, $this->project->id, 'OPEN'], [$tender->created_by, $tender->project_id, $tender->status]);
        $this->assertTrue(AuditLog::where('auditable_type', $tender->getMorphClass())->where('auditable_id', $tender->id)->where('action', 'created')->where('user_id', $this->manager->id)->exists());

        $this->assertFields([
            'id' => $tender->id, 'number' => $tender->number, 'title' => 'Slab concrete', 'status' => 'OPEN', 'due_on' => '2026-12-15',
            'project_id' => $this->project->id, 'scope' => 'With pump', 'boq_amount' => '100000.00', 'lowest_amount' => null,
            'quote_count' => 0, 'quotes' => [], 'can' => ['quote' => true, 'award' => true], 'work_order_id' => null,
        ], $response->json('data'));
        $this->assertSame([[
            'id' => $line->id, 'boq_item_id' => $this->item->id, 'code' => $this->item->code, 'description' => 'RCC M25 in slab', 'unit' => 'cum',
            'qty' => '100.000', 'boq_rate' => '1000.00', 'boq_amount' => '100000.00',
        ]], $response->json('data.lines'));

        // Sent twice, for instance on a slow connection: still one tender.
        $this->postJson(route('api.boq.tenders.store', $this->item), $form)->assertOk()->assertJsonPath('data.id', $tender->id);
        $this->assertSame(1, ContractorTender::count());
    }

    public function test_a_tender_needs_a_title_and_a_well_formed_key(): void
    {
        $this->as($this->manager)->postJson(route('api.boq.tenders.store', $this->item), ['title' => '', 'due_on' => 'soon', 'client_uuid' => 'not-a-uuid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'due_on', 'client_uuid']);

        $this->assertSame(0, ContractorTender::count());
    }

    // Contractors

    public function test_the_picker_lists_the_companys_subcontractors_and_searches_them(): void
    {
        $tiles = $this->contractorNamed('Shree Tiles');
        $tiles->update(['phone' => '0000000001']);
        $this->contractorNamed('Om Formwork');
        $this->contractorNamed('Shree Gone')->delete();
        Party::create(['organization_id' => $this->organization->id, 'type' => 'vendor', 'name' => 'Shree Cement']);
        Party::create(['organization_id' => $this->tenant('Other Co')->id, 'type' => 'subcontractor', 'name' => 'Shree Outsider']);

        $response = $this->as($this->manager)->getJson(route('api.contractor-control.contractors.index'))->assertOk();
        $this->assertSame(['Om Formwork', 'Shree Tiles'], array_column($response->json('data'), 'name'));
        $response->assertJsonPath('data.1', ['id' => $tiles->id, 'name' => 'Shree Tiles', 'phone' => '0000000001'])
            ->assertJsonPath('current_page', 1)->assertJsonPath('last_page', 1)->assertJsonPath('total', 2);

        $names = fn (string $search) => array_column($this->getJson(route('api.contractor-control.contractors.index', ['q' => $search]))->assertOk()->json('data'), 'name');
        $this->assertSame(['Shree Tiles'], $names('shree'), 'Any case, and never a vendor or another company\'s contractor.');
        $this->assertSame(['Shree Tiles'], $names('00001'), 'By phone too.');
        $this->assertSame([], $names('%'), 'A wildcard is read as itself.');

        $this->getJson(route('api.contractor-control.contractors.index', ['q' => ['a']]))->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    public function test_a_contractor_is_added_from_the_app_and_a_name_already_there_comes_back(): void
    {
        $response = $this->as($this->manager)->postJson(route('api.contractor-control.contractors.store'), [
            'name' => '  Shree Tiles ', 'phone' => '0000000001', 'gstin' => '27aaaaa0000a1z5', 'client_uuid' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('data.name', 'Shree Tiles')->assertJsonPath('data.phone', '0000000001')->assertJsonPath('message', null);

        $party = Party::findOrFail($response->json('data.id'));
        $this->assertSame(['subcontractor', $this->organization->id, '27AAAAA0000A1Z5'], [$party->type, $party->organization_id, $party->gstin]);

        $this->postJson(route('api.contractor-control.contractors.store'), ['name' => 'SHREE TILES', 'phone' => '0000000002'])
            ->assertOk()->assertJsonPath('data.id', $party->id)->assertJsonPath('data.phone', '0000000001')
            ->assertJsonPath('message', 'Shree Tiles is already on your list.');
        $this->assertSame(1, Party::where('type', 'subcontractor')->count());

        $this->postJson(route('api.contractor-control.contractors.store'), ['name' => '', 'gstin' => 'SHORT', 'client_uuid' => 'not-a-uuid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'gstin', 'client_uuid']);
    }

    // Comparing the quotes

    public function test_the_tender_sets_every_quote_against_the_boq_for_someone_who_sees_money(): void
    {
        [$tender, $old, $current, $b] = $this->flooring();

        $data = $this->as($this->manager)->getJson(route('api.contractor-control.tenders.show', $tender))->assertOk()->json('data');

        $this->assertSame([$b->id, $current->id, $old->id], array_column($data['quotes'], 'bid_id'), 'The current quotes cheapest first, then the replaced one.');
        $this->assertSame([true, true, false], array_column($data['quotes'], 'current'));
        $this->assertSame([null, null, $current->id], array_column($data['quotes'], 'replaced_by'));
        $this->assertSame([true, false, false], array_column($data['quotes'], 'lowest'));
        $this->assertFields(['quote_count' => 2, 'boq_amount' => '100000.00', 'lowest_amount' => '90000.00', 'status' => 'OPEN', 'can' => ['quote' => true, 'award' => true]], $data);

        $this->assertFields([
            'contractor' => 'Contractor B', 'party_id' => $b->party_id, 'amount' => '90000.00', 'diff' => '-10000.00', 'diff_pct' => '-10.00',
            'received_on' => '2026-11-04', 'valid_until' => '2026-12-31', 'includes_material' => true, 'completion_days' => 45,
            'payment_terms' => '30 days after the bill', 'remarks' => 'Tiles from Morbi', 'has_quotation' => true, 'quotation_name' => 'quotation.pdf',
            'selected' => false, 'entered_by' => $this->owner->name,
        ], $data['quotes'][0]);
        $this->assertSame([['tender_item_id' => $this->lineId($tender), 'boq_item_id' => $this->item->id, 'rate' => '900.00', 'amount' => '90000.00', 'diff' => '-100.00']], $data['quotes'][0]['rates']);
        $this->assertFields(['amount' => '110000.00', 'diff' => '10000.00', 'diff_pct' => '10.00', 'has_quotation' => false, 'includes_material' => null], $data['quotes'][1]);
        $this->assertSame('1200.00', $data['quotes'][2]['rates'][0]['rate'], 'The replaced quote keeps its own figures.');
    }

    public function test_site_staff_see_who_quoted_and_what_they_offer_but_no_price(): void
    {
        [$tender, $old, $current, $b] = $this->flooring();

        foreach (['engineer', 'viewer'] as $role) {
            $response = $this->as($role)->getJson(route('api.contractor-control.tenders.show', $tender))->assertOk();

            $this->assertNoMoney($response, $role);
            $data = $response->json('data');

            // Cheapest first would itself say who quoted least, so the current ones come in the order received.
            $this->assertSame([$current->id, $b->id, $old->id], array_column($data['quotes'], 'bid_id'), $role);
            $this->assertSame(['Contractor A', 'Contractor B', 'Contractor A'], array_column($data['quotes'], 'contractor'));
            $this->assertFields(['quote_count' => 2, 'title' => 'Flooring', 'status' => 'OPEN', 'can' => ['quote' => false, 'award' => false]], $data, $role);
            $this->assertFields(['received_on' => '2026-11-04', 'valid_until' => '2026-12-31', 'includes_material' => true, 'completion_days' => 45, 'current' => true], $data['quotes'][1], $role);
            $this->assertSame(['id', 'boq_item_id', 'code', 'description', 'unit', 'qty'], array_keys($data['lines'][0]));
        }
    }

    public function test_accounts_see_every_figure_but_may_neither_quote_nor_choose(): void
    {
        [$tender] = $this->flooring();

        $this->as('accounts')->getJson(route('api.contractor-control.tenders.show', $tender))->assertOk()
            ->assertJsonPath('data.lowest_amount', '90000.00')->assertJsonPath('data.quotes.0.payment_terms', '30 days after the bill')
            ->assertJsonPath('data.can', ['quote' => false, 'award' => false]);
    }

    // Adding a quote

    public function test_a_quote_comes_in_from_the_app_with_a_new_contractor_and_the_quotation_file(): void
    {
        $tender = $this->tender();
        $uuid = (string) Str::uuid();
        $form = fn () => [
            'client_uuid' => $uuid,
            'new_contractor' => 'Shree Tiles', 'new_contractor_phone' => '0000000001',
            'rates' => [$this->lineId($tender) => '925.50'],
            'received_on' => today()->subDay()->toDateString(), 'valid_until' => today()->addMonth()->toDateString(),
            'includes_material' => '1', 'completion_days' => '30', 'payment_terms' => '30 days after the bill', 'remarks' => 'Tiles from Morbi',
            'quotation' => UploadedFile::fake()->create('shree-tiles.pdf', 20, 'application/pdf'),
        ];

        $response = $this->as($this->manager)->post(route('api.contractor-control.tenders.bids.store', $tender), $form(), ['Accept' => 'application/json'])->assertCreated();

        $bid = ContractorBid::sole();
        $response->assertJsonPath('bid_id', $bid->id)->assertJsonPath('data.id', $tender->id)->assertJsonPath('data.quote_count', 1);
        $this->assertFields([
            'bid_id' => $bid->id, 'contractor' => 'Shree Tiles', 'amount' => '92550.00', 'diff' => '-7450.00', 'lowest' => true,
            'received_on' => today()->subDay()->toDateString(), 'includes_material' => true, 'completion_days' => 30,
            'has_quotation' => true, 'quotation_name' => 'shree-tiles.pdf', 'entered_by' => $this->manager->name,
        ], $response->json('data.quotes.0'));
        $this->assertSame(['subcontractor', '0000000001'], [$bid->party->type, $bid->party->phone]);
        Storage::assertExists($bid->quotation_path);

        // Sent again on a slow connection: one quote and one file.
        $this->post(route('api.contractor-control.tenders.bids.store', $tender), $form(), ['Accept' => 'application/json'])->assertOk()->assertJsonPath('bid_id', $bid->id);
        $this->assertSame(1, ContractorBid::count());
        $this->assertSame([$bid->quotation_path], Storage::allFiles());
    }

    public function test_a_quote_can_be_one_figure_and_a_later_one_replaces_the_same_contractors_earlier_quote(): void
    {
        $other = $this->makeBoqItem($this->project, '10.000', '500.00');
        $tender = $this->tender([$this->item, $other]);
        $party = $this->contractorNamed('Contractor A');

        $first = $this->as($this->manager)->postJson(route('api.contractor-control.tenders.bids.store', $tender), ['party_id' => $party->id, 'quoted_amount' => '94500'])
            ->assertCreated()->assertJsonPath('data.quotes.0.amount', '94500.00')->json('bid_id');

        // Spread across the lines in proportion to their BOQ amounts: 0.9 of 1000 and of 500.
        $this->assertSame(['900.00', '450.00'], array_column(collect($this->getJson(route('api.contractor-control.tenders.show', $tender))->json('data.quotes.0.rates'))->sortBy('tender_item_id')->values()->all(), 'rate'));

        $second = $this->postJson(route('api.contractor-control.tenders.bids.store', $tender), ['party_id' => $party->id, 'quoted_amount' => '93000.50'])->assertCreated();

        $second->assertJsonPath('data.quote_count', 1)->assertJsonPath('data.quotes.0.amount', '93000.50')
            ->assertJsonPath('data.quotes.1.bid_id', $first)->assertJsonPath('data.quotes.1.current', false)->assertJsonPath('data.quotes.1.replaced_by', $second->json('bid_id'));
    }

    public function test_a_quote_without_a_price_or_a_proper_contractor_comes_back_against_each_field(): void
    {
        $tender = $this->tender();
        $party = $this->contractorNamed('Contractor A');
        $outsider = Party::create(['organization_id' => $this->tenant('Other Co')->id, 'type' => 'subcontractor', 'name' => 'Outsider']);
        $url = route('api.contractor-control.tenders.bids.store', $tender);

        $this->as($this->manager)->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['party_id']);
        $this->postJson($url, ['party_id' => $party->id])->assertUnprocessable()->assertJsonValidationErrors(['quoted_amount']);
        $this->postJson($url, ['party_id' => $outsider->id, 'quoted_amount' => '100'])->assertUnprocessable()->assertJsonValidationErrors(['party_id']);
        $this->postJson($url, ['party_id' => $party->id, 'rates' => [$this->lineId($tender) => '0']])->assertUnprocessable()->assertJsonValidationErrors(['rates']);
        $this->postJson($url, ['party_id' => $party->id, 'quoted_amount' => '100', 'valid_until' => today()->subDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors(['valid_until']);
        $this->post($url, ['party_id' => $party->id, 'quoted_amount' => '100', 'quotation' => UploadedFile::fake()->create('quote.exe', 10)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['quotation']);

        $this->service->cancel($tender);
        $this->postJson($url, ['party_id' => $party->id, 'quoted_amount' => '100'])->assertUnprocessable()->assertJsonValidationErrors(['tender']);

        $this->assertSame(0, ContractorBid::count());
        $this->assertSame([], Storage::allFiles());
    }

    // The quotation file

    public function test_the_quotation_file_streams_only_for_people_who_see_money(): void
    {
        $tender = $this->tender();
        $bid = $this->quotationOn($this->quote($tender, 'Contractor A', '900'));
        $url = route('api.contractor-control.tenders.bids.quotation', $bid);

        foreach (['owner', 'pm', 'qs', 'accounts'] as $role) {
            $response = $this->as($role)->get($url)->assertOk();
            $this->assertSame('the quotation', $response->streamedContent(), $role);
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
        }

        foreach (['engineer', 'viewer', 'store'] as $role) {
            $this->as($role)->getJson($url)->assertForbidden();
        }

        $plain = $this->quote($tender, 'Contractor B', '950');
        $this->as($this->manager)->getJson(route('api.contractor-control.tenders.bids.quotation', $plain))->assertNotFound();
    }

    // Choosing the winner

    public function test_choosing_a_quote_makes_the_work_order_at_its_rates_once(): void
    {
        $tender = $this->tender();
        $dearer = $this->quote($tender, 'Contractor A', '1100');
        $this->quote($tender, 'Contractor B', '900');

        $response = $this->as($this->manager)->postJson(route('api.contractor-control.tenders.award', $tender), [...$this->awardTerms($dearer), 'client_uuid' => (string) Str::uuid()])->assertCreated();

        $workOrder = WorkOrder::sole();
        $response->assertJsonPath('work_order', ['id' => $workOrder->id, 'number' => $workOrder->number])
            ->assertJsonPath('data.status', 'AWARDED')->assertJsonPath('data.work_order_id', $workOrder->id)->assertJsonPath('data.work_order_number', $workOrder->number)
            ->assertJsonPath('data.can', ['quote' => false, 'award' => false]);
        $this->assertSame([$dearer->party_id, '1100.00', 'ACTIVE'], [$workOrder->party_id, $workOrder->items()->sole()->rate, $workOrder->status]);
        $this->assertTrue($dearer->refresh()->is_selected);
        $this->assertTrue(AuditLog::where('action', 'contractor_tender.awarded')->where('auditable_id', $tender->id)->exists());
        $this->assertTrue(collect($response->json('data.quotes'))->firstWhere('bid_id', $dearer->id)['selected']);

        // Sent again: the same work order, and nothing new.
        $this->postJson(route('api.contractor-control.tenders.award', $tender), $this->awardTerms($dearer))->assertOk()->assertJsonPath('work_order.id', $workOrder->id);
        $this->assertSame(1, WorkOrder::count());
    }

    public function test_a_replaced_quote_or_one_from_another_tender_cannot_be_chosen(): void
    {
        $tender = $this->tender();
        $party = $this->contractorNamed('Reviser');
        $old = $this->quote($tender, $party, '950');
        $this->quote($tender, $party, '980');
        $elsewhere = $this->quote($this->tender([], 'Painting'), 'Contractor C', '900');
        $url = route('api.contractor-control.tenders.award', $tender);

        $this->as($this->manager)->postJson($url, $this->awardTerms($old))->assertUnprocessable()->assertJsonValidationErrors('bid_id');
        $this->postJson($url, $this->awardTerms($elsewhere))->assertUnprocessable()->assertJsonValidationErrors('bid_id');
        $this->postJson($url, ['bid_id' => $old->id])->assertUnprocessable()
            ->assertJsonValidationErrors(['retention_pct', 'tds_pct', 'gst_pct', 'advance_amount', 'advance_recovery_pct', 'deduction_basis', 'deduction_order']);

        $this->assertSame(0, WorkOrder::count());
    }

    // Who may do what

    /** Each endpoint as each role. What the web lets a role do, the app lets it do, and nothing more. */
    public function test_each_role_reaches_exactly_what_the_web_lets_it(): void
    {
        $tender = $this->tender();
        $bid = $this->quotationOn($this->quote($tender, 'Contractor A', '900'));

        $expected = [
            //                     owner pm   qs   accounts engineer store viewer
            'boq' => [200, 200, 200, 200, 200, 200, 200],
            'line' => [200, 200, 200, 200, 200, 403, 200],
            'start' => [201, 201, 201, 403, 403, 403, 403],
            'contractors' => [200, 200, 200, 403, 403, 403, 403],
            'add contractor' => [201, 201, 201, 403, 403, 403, 403],
            'tender' => [200, 200, 200, 200, 200, 403, 200],
            'quote' => [201, 201, 201, 403, 403, 403, 403],
            'quotation' => [200, 200, 200, 200, 403, 403, 403],
            'award' => [201, 201, 403, 403, 403, 403, 403],
        ];

        foreach (self::ROLES as $i => $role) {
            // Each role chooses on a tender of its own, so one award does not close the next.
            $awardable = $this->tender([], 'Painting '.$role);
            $awardBid = $this->quote($awardable, 'Painter '.$role, '800');
            $this->as($role);

            $got = [
                'boq' => $this->getJson($this->boqUrl())->getStatusCode(),
                'line' => $this->getJson(route('api.boq.tenders.show', $this->item))->getStatusCode(),
                'start' => $this->postJson(route('api.boq.tenders.store', $this->item), ['title' => 'By '.$role])->getStatusCode(),
                'contractors' => $this->getJson(route('api.contractor-control.contractors.index'))->getStatusCode(),
                'add contractor' => $this->postJson(route('api.contractor-control.contractors.store'), ['name' => 'Added by '.$role])->getStatusCode(),
                'tender' => $this->getJson(route('api.contractor-control.tenders.show', $tender))->getStatusCode(),
                'quote' => $this->postJson(route('api.contractor-control.tenders.bids.store', $tender), ['new_contractor' => 'Quoted by '.$role, 'quoted_amount' => '95000'])->getStatusCode(),
                'quotation' => $this->get(route('api.contractor-control.tenders.bids.quotation', $bid))->getStatusCode(),
                'award' => $this->postJson(route('api.contractor-control.tenders.award', $awardable), $this->awardTerms($awardBid))->getStatusCode(),
            ];

            $this->assertSame(array_map(fn (array $row) => $row[$i], $expected), $got, $role);
        }

        // Only the refused writes left nothing behind: three roles started a tender, quoted and added a contractor; two chose.
        $this->assertSame(1 + 7 + 3, ContractorTender::count());
        $this->assertSame(1 + 7 + 3, ContractorBid::count());
        $this->assertSame(3, Party::where('name', 'like', 'Added by %')->count());
        $this->assertSame(2, WorkOrder::count());
    }

    // Other companies and other projects

    public function test_another_company_reaches_none_of_it_even_by_guessing_ids(): void
    {
        $tender = $this->tender();
        $bid = $this->quotationOn($this->quote($tender, 'Shree Tiles', '900'));
        $ours = Party::where('name', 'Shree Tiles')->sole();
        $other = $this->tenant('Other Co');
        $stranger = $this->member($other, 'owner');
        Sanctum::actingAs($stranger);

        $this->getJson($this->boqUrl())->assertNotFound();
        $this->getJson(route('api.boq.tenders.show', $this->item))->assertNotFound();
        $this->postJson(route('api.boq.tenders.store', $this->item), ['title' => 'Planted'])->assertNotFound();
        $this->getJson(route('api.contractor-control.tenders.show', $tender))->assertNotFound();
        $this->postJson(route('api.contractor-control.tenders.bids.store', $tender), ['quoted_amount' => '1', 'new_contractor' => 'Planted'])->assertNotFound();
        $this->get(route('api.contractor-control.tenders.bids.quotation', $bid))->assertNotFound();
        $this->postJson(route('api.contractor-control.tenders.award', $tender), $this->awardTerms($bid))->assertNotFound();

        // Their picker shows only their own contractors, and our contractor's name makes them one of their own.
        $this->assertSame([], $this->getJson(route('api.contractor-control.contractors.index', ['q' => 'shree']))->assertOk()->json('data'));
        $theirs = $this->postJson(route('api.contractor-control.contractors.store'), ['name' => 'Shree Tiles'])->assertCreated()->json('data.id');
        $this->assertNotSame($ours->id, $theirs);

        // On a tender of their own, our contractor cannot be named.
        $theirProject = $this->makeProject($other);
        $theirTender = $this->service->create($theirProject, ['title' => 'Theirs', 'boq_item_ids' => [$this->makeBoqItem($theirProject)->id]], $stranger);
        $this->postJson(route('api.contractor-control.tenders.bids.store', $theirTender), ['party_id' => $ours->id, 'quoted_amount' => '100'])
            ->assertUnprocessable()->assertJsonValidationErrors('party_id');

        $this->assertSame(2, ContractorTender::withoutGlobalScopes()->count());
        $this->assertSame(1, ContractorBid::withoutGlobalScopes()->count());
        $this->assertSame(0, WorkOrder::withoutGlobalScopes()->count());
        $this->assertTrue($bid->refresh()->isCurrent());
    }

    public function test_someone_limited_to_another_project_reaches_none_of_this_one(): void
    {
        $uuid = (string) Str::uuid();
        $tender = $this->service->create($this->project, ['title' => 'Flooring', 'boq_item_ids' => [$this->item->id], 'client_uuid' => $uuid], $this->owner);
        $bid = $this->quotationOn($this->quote($tender, 'Contractor A', '900'));
        $theirProject = $this->makeProject($this->organization);
        $theirItem = $this->makeBoqItem($theirProject);
        Sanctum::actingAs($this->limitedTo($theirProject->id));

        $this->getJson($this->boqUrl())->assertNotFound();
        $this->getJson(route('api.boq.tenders.show', $this->item))->assertNotFound();
        $this->postJson(route('api.boq.tenders.store', $this->item), ['title' => 'Planted'])->assertNotFound();
        $this->getJson(route('api.contractor-control.tenders.show', $tender))->assertNotFound();
        $this->postJson(route('api.contractor-control.tenders.bids.store', $tender), ['quoted_amount' => '1', 'new_contractor' => 'Planted'])->assertNotFound();
        $this->get(route('api.contractor-control.tenders.bids.quotation', $bid))->assertNotFound();
        $this->postJson(route('api.contractor-control.tenders.award', $tender), $this->awardTerms($bid))->assertNotFound();

        // A key already used on this project is refused rather than handing that tender over.
        $this->postJson(route('api.boq.tenders.store', $theirItem), ['title' => 'Theirs', 'client_uuid' => $uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('client_uuid');

        // Contractors belong to the company, as on the web.
        $this->getJson(route('api.contractor-control.contractors.index'))->assertOk()->assertJsonPath('data.0.name', 'Contractor A');

        // Someone limited to this project reaches all of it.
        Sanctum::actingAs($this->limitedTo($this->project->id));
        $this->getJson(route('api.contractor-control.tenders.show', $tender))->assertOk()->assertJsonPath('data.quotes.0.amount', '90000.00');
        $this->getJson(route('api.boq.tenders.show', $this->item))->assertOk()->assertJsonPath('data.tender_count', 1);
        $this->get(route('api.contractor-control.tenders.bids.quotation', $bid))->assertOk();

        $this->assertSame(1, ContractorTender::withoutGlobalScopes()->count());
        $this->assertSame(1, ContractorBid::withoutGlobalScopes()->count());
        $this->assertSame(0, WorkOrder::withoutGlobalScopes()->count());
    }
}
