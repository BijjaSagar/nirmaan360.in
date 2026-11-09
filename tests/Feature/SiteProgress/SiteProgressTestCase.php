<?php

namespace Tests\Feature\SiteProgress;

use App\Models\BoqItem;
use App\Models\Organization;
use App\Models\ProgressEntry;
use App\Models\Project;
use App\Models\ProjectFloor;
use App\Models\User;
use App\Services\SiteProgress\FloorService;
use App\Services\SiteProgress\ProgressEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\BuildsTenant;
use Tests\TestCase;

/** Shared set-up for the site progress tests. Every BOQ rate in these tests is the same odd figure, so a leak is easy to spot. */
abstract class SiteProgressTestCase extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    /** The rate every BOQ item gets. No page or endpoint of this module may show it. */
    protected const RATE = '7777.77';

    protected Organization $organization;

    protected Project $project;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake();

        $this->organization = $this->tenant();
        $this->owner = $this->member($this->organization, 'owner');
        $this->project = $this->makeProject($this->organization);
    }

    protected function item(string $qty = '100.000', ?Project $project = null, string $rate = self::RATE): BoqItem
    {
        return $this->makeBoqItem($project ?? $this->project, $qty, $rate);
    }

    protected function floor(string $name = '2nd floor', ?int $parentId = null, ?Project $project = null): ProjectFloor
    {
        $this->actingAs($this->owner);

        return app(FloorService::class)->create($project ?? $this->project, ['name' => $name, 'parent_id' => $parentId]);
    }

    protected function work(BoqItem $item, string $qty, ?ProjectFloor $floor = null, array $extra = [], ?User $by = null): ProgressEntry
    {
        $this->actingAs($by ?? $this->owner);

        return app(ProgressEntryService::class)->record($this->project, [
            'boq_item_id' => $item->id,
            'project_floor_id' => $floor?->id,
            'qty_done' => $qty,
            ...$extra,
        ], $by ?? $this->owner);
    }

    /** A company on another tenant, with its own project and owner. */
    protected function stranger(): object
    {
        $organization = $this->tenant('Other Builders');
        $owner = $this->member($organization, 'owner');
        $project = $this->makeProject($organization, ['name' => 'Other Project']);

        return (object) ['organization' => $organization, 'owner' => $owner, 'project' => $project];
    }

    /** No rate, amount or budget in anything shown. */
    protected function assertNoMoney(string $content): void
    {
        $this->assertStringNotContainsString('7777', $content);
        $this->assertStringNotContainsString('7,777', $content);
        $this->assertStringNotContainsString('₹', $content);
    }

    /** No JSON key that names money, anywhere in a response. */
    protected function assertNoMoneyKeys(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->assertDoesNotMatchRegularExpression('/rate|amount|budget|cost|price|value_|payment|contract/i', (string) $key, "A money key '$key' was returned");

            if (is_array($value)) {
                $this->assertNoMoneyKeys($value);
            }
        }

        $this->assertStringNotContainsString('7777', json_encode($data));
    }
}
