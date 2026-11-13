<?php

namespace Tests\Feature\RateHelper;

use App\Models\BoqItem;
use App\Models\BoqMaterialNorm;
use App\Models\CrmTender;
use App\Models\CrmTenderBoqItem;
use App\Models\CustomRole;
use App\Models\DeliveryChallan;
use App\Models\Material;
use App\Models\MaterialStore;
use App\Models\MaterialUnit;
use App\Models\Organization;
use App\Models\Party;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\RateLibraryItem;
use App\Models\RateSuggestionLine;
use App\Models\RateSuggestionRun;
use App\Models\User;
use App\Services\Access\TeamAccessService;
use App\Services\RateHelper\RateSources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\BuildsTenant;
use Tests\TestCase;

/**
 * An invented company on the Business plan with one of every role, the project being priced, and a
 * second project to take rates from. Every company, name and figure here is made up.
 */
abstract class RateHelperTestCase extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    /** The refusal for a row that has gone, moved out of the person's reach or out of their role, or changed its unit. */
    protected const GONE = 'This rate is no longer available to you. It may have been removed, changed or moved out of your projects.';

    /** The refusal for a row that is still there but no longer what the person's page showed. */
    protected const CHANGED = 'This rate or its description changed after you opened the page. Check what is shown now before you accept.';

    protected Organization $org;

    protected User $owner;

    protected User $pm;

    protected User $qs;

    protected User $accounts;

    protected User $engineer;

    protected User $store;

    protected User $viewer;

    /** The project whose BOQ is being priced. */
    protected Project $project;

    /** Another project of the same company. */
    protected Project $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->org = $this->tenant('Acme Infra', 'business');

        foreach (['owner', 'pm', 'qs', 'accounts', 'engineer', 'store', 'viewer'] as $role) {
            $this->{$role} = $this->member($this->org, $role);
        }

        $this->project = $this->makeProject($this->org, ['code' => 'NB1', 'name' => 'North Block']);
        $this->other = $this->makeProject($this->org, ['code' => 'GP2', 'name' => 'Maplewood Plaza']);
    }

    /** A BOQ line. Its rate is 0 unless given: that is the kind of line the helper is for. */
    protected function line(string $description, string $unit = 'cum', string $rate = '0.00', ?Project $project = null, array $attributes = []): BoqItem
    {
        $project ??= $this->project;

        return BoqItem::withoutGlobalScopes()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'code' => 'L'.Str::upper(Str::random(6)),
            'description' => $description,
            'unit' => $unit,
            'qty' => '100.000',
            'rate' => $rate,
            'sort' => (int) BoqItem::withoutGlobalScopes()->where('project_id', $project->id)->max('sort') + 1,
            ...$attributes,
        ]);
    }

    protected function libraryRate(string $item, string $unit, string $rate, ?Organization $organization = null, array $attributes = []): RateLibraryItem
    {
        return RateLibraryItem::withoutGlobalScopes()->create([
            'organization_id' => ($organization ?? $this->org)->id,
            'item' => $item,
            'unit' => $unit,
            'rate' => $rate,
            ...$attributes,
        ]);
    }

    /** A tender with one priced BOQ line. */
    protected function tenderLine(string $description, string $unit, string $rate, string $stage = 'submitted', array $tender = [], ?Organization $organization = null): CrmTenderBoqItem
    {
        $organization ??= $this->org;

        $row = CrmTender::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'title' => 'Ward office building',
            'authority' => 'District Council',
            'stage' => $stage,
            ...$tender,
        ]);

        return CrmTenderBoqItem::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'tender_id' => $row->id,
            'uuid' => (string) Str::uuid(),
            'code' => 'T'.Str::upper(Str::random(4)),
            'description' => $description,
            'unit' => $unit,
            'qty' => '10.000',
            'rate' => $rate,
        ]);
    }

    /** A material in the catalogue, with its base unit. */
    protected function material(string $name, string $unit = 'bag', ?Organization $organization = null): Material
    {
        $organization ??= $this->org;
        $baseUnit = MaterialUnit::withoutGlobalScopes()->firstOrCreate(['organization_id' => $organization->id, 'code' => $unit]);

        return Material::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'code' => 'M'.Str::upper(Str::random(5)),
            'name' => $name,
            'base_unit_id' => $baseUnit->id,
        ]);
    }

    protected function norm(BoqItem $item, Material $material, string $qtyPerUnit, string $wastagePct = '0'): BoqMaterialNorm
    {
        return BoqMaterialNorm::withoutGlobalScopes()->create([
            'organization_id' => $item->organization_id,
            'project_id' => $item->project_id,
            'boq_item_id' => $item->id,
            'material_id' => $material->id,
            'qty_per_unit' => $qtyPerUnit,
            'wastage_pct' => $wastagePct,
        ]);
    }

    /** An issued purchase order with one line: $baseQty base units for $amount before GST. */
    protected function purchase(Material $material, string $baseQty, string $amount, string $date, ?Project $project = null, string $status = 'ISSUED'): PurchaseOrder
    {
        $project ??= $this->project;
        $org = $project->organization_id;
        $vendor = Party::withoutGlobalScopes()->firstOrCreate(['organization_id' => $org, 'type' => 'vendor', 'name' => 'Sample Traders']);
        $store = MaterialStore::withoutGlobalScopes()->firstOrCreate(['organization_id' => $org, 'project_id' => $project->id, 'name' => 'Main store']);

        $order = PurchaseOrder::withoutGlobalScopes()->create([
            'organization_id' => $org, 'project_id' => $project->id, 'party_id' => $vendor->id, 'store_id' => $store->id,
            'number' => 'PO-'.Str::upper(Str::random(6)), 'po_date' => $date, 'status' => 'DRAFT',
            'subtotal' => $amount, 'gst_amount' => '0.00', 'total' => $amount,
        ]);

        DB::table('purchase_order_items')->insert([
            'organization_id' => $org, 'purchase_order_id' => $order->id, 'material_id' => $material->id,
            'qty' => $baseQty, 'unit_id' => $material->base_unit_id, 'base_qty' => $baseQty,
            'rate' => '0.00', 'gst_pct' => '18.00', 'amount' => $amount, 'gst_amount' => '0.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The status column is one a locked order may still change, so this is how an order is issued.
        if ($status !== 'DRAFT') {
            DB::table('purchase_orders')->where('id', $order->id)->update(['status' => $status]);
        }

        return $order->refresh();
    }

    /** A posted delivery challan whose bill was entered: $baseQty base units for $billAmount, as on the bill. */
    protected function challanBill(Material $material, string $baseQty, string $billAmount, string $billDate, ?Project $project = null, string $status = 'POSTED'): DeliveryChallan
    {
        $project ??= $this->project;
        $org = $project->organization_id;
        $supplier = Party::withoutGlobalScopes()->firstOrCreate(['organization_id' => $org, 'type' => 'vendor', 'name' => 'Sample Traders']);

        return DeliveryChallan::withoutGlobalScopes()->create([
            'organization_id' => $org, 'project_id' => $project->id, 'party_id' => $supplier->id,
            'material_id' => $material->id, 'unit_id' => $material->base_unit_id,
            'number' => 'DC-'.Str::upper(Str::random(6)), 'challan_date' => $billDate,
            'qty' => $baseQty, 'base_qty' => $baseQty,
            'bill_status' => 'attached', 'bill_no' => 'B-'.Str::upper(Str::random(4)), 'bill_date' => $billDate, 'bill_amount' => $billAmount,
            'status' => $status,
        ]);
    }

    /** Someone holding exactly these permissions. */
    protected function holding(array $permissions, ?Organization $organization = null): User
    {
        $organization ??= $this->org;
        $role = CustomRole::withoutGlobalScopes()->create(['organization_id' => $organization->id, 'name' => 'Role '.uniqid(), 'permissions' => $permissions]);
        $user = $this->member($organization, $role->baseRole());
        $user->forceFill(['custom_role_id' => $role->id])->save();

        return $user;
    }

    /** Someone with this role who may only see the given projects. */
    protected function limited(string $role, Project ...$projects): User
    {
        $user = $this->member($this->org, $role);
        app(TeamAccessService::class)->applyProjects($user, true, array_map(fn (Project $project) => $project->id, $projects));

        return $user->refresh();
    }

    /** A second company with a project of its own. @return array{0: Organization, 1: User, 2: Project} */
    protected function rival(): array
    {
        $org = $this->tenant('Rival Builders', 'business');
        $owner = $this->member($org, 'owner');
        $project = $this->makeProject($org, ['code' => 'TC1', 'name' => 'Rival Heights']);

        return [$org, $owner, $project];
    }

    /** Presses "Suggest rates" for these lines as this person. */
    protected function suggest(User $by, BoqItem ...$items): TestResponse
    {
        return $this->actingAs($by)->post(route('rate-suggestions.store', $items[0]->project_id), [
            'client_uuid' => (string) Str::uuid(),
            'boq_item_ids' => array_map(fn (BoqItem $item) => $item->id, $items),
        ]);
    }

    /** The run a person just started for these lines. */
    protected function startRun(User $by, BoqItem ...$items): RateSuggestionRun
    {
        $this->suggest($by, ...$items)->assertRedirect();

        return RateSuggestionRun::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    protected function lineOf(RateSuggestionRun $run, BoqItem $item): RateSuggestionLine
    {
        return RateSuggestionLine::withoutGlobalScopes()->where('run_id', $run->id)->where('boq_item_id', $item->id)->firstOrFail();
    }

    /** @return list<array<string, mixed>> The stored shortlist of a line. */
    protected function shortlist(RateSuggestionRun $run, BoqItem $item): array
    {
        return $this->lineOf($run, $item)->candidates;
    }

    /** The ref a source's row has on a line, or null when it is not on the list. */
    protected function refOf(RateSuggestionRun $run, BoqItem $item, string $source, int $rowId): ?string
    {
        foreach ($this->shortlist($run, $item) as $candidate) {
            if ($candidate['source'] === $source && (int) $candidate['row_id'] === $rowId) {
                return $candidate['ref'];
            }
        }

        return null;
    }

    /**
     * Presses "Accept this rate" for this ref as this person, on the review screen as it is now: the
     * form sends back the fingerprint of the figure it shows, as a browser would. Give 'shown' in
     * $extra to press it on a page opened earlier.
     */
    protected function accept(User $by, RateSuggestionRun $run, BoqItem $item, string $ref, array $extra = []): TestResponse
    {
        $line = $this->lineOf($run, $item);
        $shown = array_key_exists('shown', $extra) ? [] : array_filter(['shown' => $this->shownOn($by, $run, $line, $ref)]);

        return $this->actingAs($by)->post(route('rate-suggestions.lines.accept', [$run, $line]), [
            'candidate' => $ref,
            'mode' => RateSuggestionLine::SET_BOQ_RATE,
            ...$shown,
            ...$extra,
        ]);
    }

    /** The fingerprint the review screen puts in the accept form of this ref on this line, or null when it offers no such form. */
    protected function shownOn(User $by, RateSuggestionRun $run, RateSuggestionLine $line, string $ref): ?string
    {
        $response = $this->actingAs($by)->get(route('rate-suggestions.show', $run));

        if (! $response->isOk()) {
            return null;
        }

        $action = preg_quote(e(route('rate-suggestions.lines.accept', [$run, $line])), '#');
        $form = '#<form method="POST" action="'.$action.'"(?:(?!</form>).)*?name="candidate" value="'.preg_quote($ref, '#').'">\s*<input type="hidden" name="shown" value="([0-9a-f]{64})">#s';

        return preg_match($form, (string) $response->getContent(), $match) ? $match[1] : null;
    }

    /**
     * What a tab this person opens now keeps in the accept form of this ref, for pressing Accept on it
     * later. Fails when the page does not offer the ref, since then there is no such tab.
     */
    protected function tabOpenedBy(User $by, RateSuggestionRun $run, BoqItem $item, string $ref): string
    {
        $shown = $this->shownOn($by, $run, $this->lineOf($run, $item), $ref);
        $this->assertNotNull($shown, 'The page of '.$by->name.' offers '.$ref.'.');

        return $shown;
    }

    /**
     * The fingerprint a source row has for someone who may see it: what their page would send. For a
     * row no page of this company ever shows, such as another company's, read it as one of theirs.
     * A refusal of it can then only come from the reach and tenant checks, not from the fingerprint.
     */
    protected function fingerprintFor(User $viewer, string $source, int $rowId): string
    {
        $before = Auth::user();
        $this->actingAs($viewer);

        $figure = app(RateSources::class)->open()[$source]->rateOf($viewer, $rowId);

        $before === null ? Auth::logout() : $this->actingAs($before);
        $this->assertNotNull($figure, $viewer->name.' can see row '.$rowId.' of '.$source.'.');

        return $figure->fingerprint();
    }
}
