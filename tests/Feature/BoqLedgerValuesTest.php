<?php

namespace Tests\Feature;

use App\Models\BoqItem;
use App\Models\BoqSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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

    /** The app's BOQ list carries the same values as the web page: each line, each section and the whole BOQ. */
    public function test_the_app_boq_list_carries_each_lines_value_and_the_totals(): void
    {
        [, $owner, $project] = $this->world();
        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/projects/'.$project->id.'/boq')->assertOk();

        $response->assertJsonPath('data.0.amount', '235200.00')->assertJsonPath('data.0.balance_amount', '235200.00')
            ->assertJsonPath('data.1.amount', '35831.60')
            ->assertJsonPath('totals.amount', '1279297.71')->assertJsonPath('totals.balance_amount', '1279297.71')
            ->assertJsonPath('totals.sections', [
                ['section' => 'Civil works', 'amount' => '271031.60', 'balance_amount' => '271031.60'],
                ['section' => 'Add-ons', 'amount' => '1008266.11', 'balance_amount' => '1008266.11'],
            ]);
    }

    public function test_the_app_boq_list_gives_no_values_to_someone_who_may_not_see_money(): void
    {
        [$organization, , $project] = $this->world();
        Sanctum::actingAs($this->member($organization, 'engineer'));

        $response = $this->getJson('/api/v1/projects/'.$project->id.'/boq')->assertOk();

        $response->assertJsonPath('data.0.amount', null)->assertJsonPath('data.0.balance_amount', null)
            ->assertJsonPath('data.0.rate', null)->assertJsonPath('totals', null);
        $this->assertStringNotContainsString('235200', $response->getContent());
    }
}
