<?php

namespace Tests\Feature;

use App\Models\BoqItem;
use App\Models\BoqSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsTenant;
use Tests\TestCase;

class BoqLedgerValuesTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private function world(): array
    {
        $this->withoutVite();
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $this->actingAs($owner);
        $project = $this->makeProject($organization);
        $civil = BoqSection::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'name' => 'Civil works']);
        $extras = BoqSection::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'name' => 'Add-ons']);

        $line = fn ($section, $code, $qty, $rate) => BoqItem::create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'boq_section_id' => $section->id,
            'code' => $code, 'description' => 'Line '.$code, 'unit' => 'cum', 'qty' => $qty, 'rate' => $rate, 'sort' => (int) $code,
        ]);
        $line($civil, '1', '672.000', '350.00');
        $line($civil, '2', '67.000', '534.80');
        $line($extras, '3', '1.000', '1008266.11');

        return [$organization, $owner, $project];
    }

    public function test_each_line_shows_its_value_and_each_section_and_the_whole_boq_add_up(): void
    {
        [, $owner, $project] = $this->world();

        $this->actingAs($owner)->get(route('boq.index', $project))->assertOk()
            ->assertSee('Balance value')
            // 672 × 350 and 67 × 534.80
            ->assertSee('2,35,200.00')->assertSee('35,831.60')
            // Civil works section: 2,35,200.00 + 35,831.60
            ->assertSee('2,71,031.60')
            // Whole BOQ, with nothing measured yet, so the balance value is the same
            ->assertSee('12,79,297.71');
    }

    public function test_site_staff_see_quantities_without_any_value(): void
    {
        [$organization, , $project] = $this->world();

        $this->actingAs($this->member($organization, 'engineer'))->get(route('boq.index', $project))->assertOk()
            ->assertSee('672.000')
            ->assertDontSee('Balance value')->assertDontSee('2,35,200')->assertDontSee('12,79,297');
    }
}
