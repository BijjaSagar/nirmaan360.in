<?php

namespace Tests\Feature\FieldOps;

use App\Support\Modules;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

class ModuleRegistrationTest extends FieldOpsTestCase
{
    public function test_both_features_are_on_business_and_enterprise_only(): void
    {
        foreach (['assets', 'qhs'] as $feature) {
            $this->assertTrue($this->tenant('B', 'business')->allowsFeature($feature), "business has $feature");
            $this->assertTrue($this->tenant('E', 'enterprise')->allowsFeature($feature), "enterprise has $feature");
            $this->assertFalse($this->tenant('C', 'contractor')->allowsFeature($feature), "contractor lacks $feature");
            $this->assertFalse($this->tenant('F', 'free')->allowsFeature($feature), "free lacks $feature");
        }
    }

    public function test_each_role_gets_the_permissions_it_should(): void
    {
        $organization = $this->tenant();
        $expected = [
            'owner' => ['asset.manage', 'asset.bill', 'qhs.manage', 'safety.manage', 'checklist.manage', 'rfi.approve'],
            'pm' => ['asset.view', 'asset.manage', 'asset.log', 'asset.bill', 'qhs.manage', 'safety.manage', 'checklist.manage', 'checklist.fill', 'rfi.manage', 'rfi.approve'],
            'engineer' => ['asset.view', 'asset.log', 'qhs.manage', 'safety.manage', 'checklist.fill', 'rfi.manage'],
            'store' => ['asset.view', 'asset.log', 'safety.view', 'checklist.view'],
            'accounts' => ['asset.view', 'asset.bill', 'qhs.view'],
            'qs' => ['asset.view', 'qhs.view', 'safety.view', 'checklist.view', 'rfi.view'],
            'viewer' => ['asset.view', 'qhs.view', 'safety.view', 'checklist.view', 'rfi.view'],
        ];
        $never = [
            'engineer' => ['asset.manage', 'asset.bill', 'checklist.manage', 'rfi.approve'],
            'store' => ['asset.manage', 'asset.bill', 'qhs.view', 'safety.manage', 'rfi.view'],
            'accounts' => ['asset.log', 'asset.manage', 'safety.view', 'rfi.view'],
            'qs' => ['asset.log', 'qhs.manage', 'rfi.manage', 'checklist.fill'],
            'viewer' => ['asset.log', 'asset.bill', 'qhs.manage', 'safety.manage', 'checklist.fill', 'rfi.manage', 'rfi.approve'],
        ];

        foreach ($expected as $role => $permissions) {
            $user = $this->member($organization, $role);

            foreach ($permissions as $permission) {
                $this->assertTrue($user->can($permission), "$role can $permission");
            }

            foreach ($never[$role] ?? [] as $permission) {
                $this->assertFalse($user->can($permission), "$role cannot $permission");
            }
        }
    }

    public function test_the_menu_has_the_company_and_project_entries(): void
    {
        $business = $this->tenant();
        $contractor = $this->tenant('Small', 'contractor');

        $company = collect(Modules::nav('company', $business))->pluck('route')->all();
        $project = collect(Modules::nav('project', $business))->pluck('route')->all();

        foreach (['assets.index', 'machine-logs.index', 'asset-maintenance.index', 'hire-bills.index', 'checklists.index'] as $route) {
            $this->assertContains($route, $company);
        }

        foreach (['ncrs.index', 'quality-tests.index', 'incidents.index', 'inspections.index', 'rfis.index'] as $route) {
            $this->assertContains($route, $project);
        }

        $this->assertSame([], array_values(array_filter(Modules::nav('company', $contractor), fn ($item) => in_array($item['route'], ['assets.index', 'checklists.index']))));
    }

    public function test_every_menu_target_is_a_real_route(): void
    {
        foreach (['assets', 'qhs'] as $module) {
            foreach (config("modules.$module.nav") as $item) {
                $this->assertTrue(Route::has($item['route']), $item['route']);
            }
        }
    }

    public function test_the_project_menu_shows_for_a_project_and_respects_permissions(): void
    {
        $w = $this->world('owner');
        $this->actingAs($w->owner)->get(route('projects.show', $w->project))->assertOk()
            ->assertSee('NCRs')->assertSee('Quality tests')->assertSee('Safety')->assertSee('Inspections')->assertSee('RFIs');

        $store = $this->member($w->organization, 'store');
        Auth::forgetUser();
        $this->actingAs($store)->get(route('projects.show', $w->project))->assertOk()
            ->assertDontSee('NCRs')->assertDontSee('RFIs')->assertSee('Safety')->assertSee('Inspections');
    }

    public function test_the_icons_are_available_to_views(): void
    {
        $this->assertStringContainsString('<rect', (string) $this->blade('<x-icon name="qr" />'));
        $this->assertStringContainsString('<path', (string) $this->blade('<x-icon name="wrench" />'));
    }

    public function test_every_module_route_is_guarded_by_its_feature_and_a_permission(): void
    {
        $mine = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->getActionName(), '\\Controllers\\Assets\\') || str_contains($route->getActionName(), '\\Controllers\\Qhs\\'));

        $this->assertGreaterThan(60, $mine->count());

        foreach ($mine as $route) {
            $middleware = $route->gatherMiddleware();
            $feature = collect($middleware)->first(fn ($m) => is_string($m) && str_starts_with($m, 'feature:'));
            $this->assertContains($feature, ['feature:assets', 'feature:qhs'], $route->uri().' has no feature check');

            // File routes check the permission inside the controller, by record type.
            if (! str_contains($route->uri(), 'qhs-files')) {
                $this->assertTrue(collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'can:')), $route->uri().' has no permission check');
            }
        }
    }
}
