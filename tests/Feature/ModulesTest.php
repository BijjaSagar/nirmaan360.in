<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicController;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\BuildsTenant;
use Tests\TestCase;

class ModulesTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Route::get('/module-demo', [PublicController::class, 'pricing'])->name('demo.index');
        Route::getRoutes()->refreshNameLookups();
        config(['modules.demo' => [
            'feature' => 'demo',
            'plans' => ['business'],
            'permissions' => ['pm' => ['demo.view']],
            'nav' => [['place' => 'company', 'group' => 'Sales', 'label' => 'Demo module', 'route' => 'demo.index', 'can' => 'demo.view']],
            'icons' => ['demo-icon' => '<path d="M1 1h2"/>'],
        ]]);
        Modules::apply();
    }

    public function test_module_adds_its_permission_to_a_role(): void
    {
        $organization = $this->tenant();

        $this->assertTrue($this->member($organization, 'pm')->can('demo.view'));
        $this->assertFalse($this->member($organization, 'engineer')->can('demo.view'));
    }

    public function test_menu_shows_a_module_only_on_plans_that_include_it(): void
    {
        $business = $this->tenant('Big Co', 'business');
        $contractor = $this->tenant('Small Co', 'contractor');

        $this->assertCount(1, array_filter(Modules::nav('company', $business), fn ($item) => $item['route'] === 'demo.index'));
        $this->assertCount(0, array_filter(Modules::nav('company', $contractor), fn ($item) => $item['route'] === 'demo.index'));

        $this->actingAs($this->member($business, 'owner'))->get('/dashboard')->assertOk()->assertSee('Demo module');
        $this->actingAs($this->member($contractor, 'owner'))->get('/dashboard')->assertOk()->assertDontSee('Demo module');
    }

    public function test_menu_respects_the_users_permission(): void
    {
        $this->actingAs($this->member($this->tenant(), 'engineer'))->get('/dashboard')->assertOk()->assertDontSee('Demo module');
    }

    public function test_module_icons_work(): void
    {
        $this->assertStringContainsString('M1 1h2', (string) $this->blade('<x-icon name="demo-icon" />'));
    }

    public function test_applying_twice_does_not_repeat_entries(): void
    {
        Modules::apply();

        $this->assertSame(1, count(array_keys(config('buildos.roles.pm.permissions'), 'demo.view', true)));
        $this->assertSame(1, count(array_keys(config('buildos.plans.business.features'), 'demo', true)));
    }
}
