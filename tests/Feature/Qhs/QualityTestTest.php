<?php

namespace Tests\Feature\Qhs;

use App\Models\AuditLog;
use App\Models\QualityTest;
use App\Models\QualityTestResult;
use App\Models\Site;
use App\Services\Qhs\QualityTestService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\Feature\FieldOps\FieldOpsTestCase;

class QualityTestTest extends FieldOpsTestCase
{
    private function cubes(object $w, array $data = []): QualityTest
    {
        return app(QualityTestService::class)->create($w->project, [
            'test_type' => 'cube', 'element' => 'Slab, third floor', 'grade' => 'M25', 'cast_date' => '2026-10-01', ...$data,
        ], $w->owner);
    }

    public function test_registering_a_cube_test_schedules_three_specimens_at_7_and_28_days(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);

        $test = $this->cubes($w);

        $this->assertSame('25.00', $test->required_strength);
        $this->assertSame(6, $test->results()->count());
        $this->assertSame([7, 7, 7, 28, 28, 28], $test->results->pluck('age_days')->all());
        $this->assertSame([1, 2, 3, 4, 5, 6], $test->results->pluck('specimen_no')->all());
        $this->assertSame(['2026-10-08', '2026-10-29'], $test->results->pluck('due_on')->map->toDateString()->unique()->values()->all());
    }

    public function test_the_required_strength_can_be_set_and_other_tests_start_empty(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);

        $this->assertSame('32.50', $this->cubes($w, ['grade' => 'Design mix', 'required_strength' => '32.5'])->required_strength);
        $this->assertNull($this->cubes($w, ['grade' => 'Design mix'])->required_strength);

        $brick = $this->cubes($w, ['test_type' => 'brick', 'grade' => null, 'element' => 'Batch 14']);
        $this->assertSame(0, $brick->results()->count());

        $custom = $this->cubes($w, ['specimens_7' => 2, 'specimens_28' => 0]);
        $this->assertSame([7, 7], $custom->results->pluck('age_days')->all());
    }

    public function test_a_cube_load_is_turned_into_a_strength(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $test = $this->cubes($w);
        $first = $test->results->first();

        $this->patch(route('quality-tests.results.update', $first), ['tested_on' => '2026-10-08', 'load_kn' => '412.5', 'remarks' => 'Clean break'])->assertRedirect()->assertSessionHasNoErrors();

        $first->refresh();
        $this->assertSame('18.33', $first->strength); // 412.5 kN over 22,500 mm2
        $this->assertSame('412.50', $first->load_kn);
        $this->assertSame($w->owner->id, $first->recorded_by);
    }

    public function test_a_result_needs_a_load_or_a_strength_and_a_sensible_date(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $result = $this->cubes($w)->results->first();

        $this->patch(route('quality-tests.results.update', $result), ['tested_on' => '2026-10-08'])->assertSessionHasErrors('strength');
        $this->patch(route('quality-tests.results.update', $result), ['tested_on' => '2026-09-30', 'strength' => 20])->assertSessionHasErrors('tested_on');
        $this->patch(route('quality-tests.results.update', $result), ['tested_on' => '2999-01-01', 'strength' => 20])->assertSessionHasErrors('tested_on');
        $this->patch(route('quality-tests.results.update', $result), ['tested_on' => '2026-10-08', 'load_kn' => -5])->assertSessionHasErrors('load_kn');
        $this->assertNull($result->refresh()->strength);
    }

    public function test_other_tests_need_the_strength_typed(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $test = $this->cubes($w, ['test_type' => 'cylinder', 'grade' => 'M30']);
        $result = app(QualityTestService::class)->addSpecimen($test, 28);

        $this->patch(route('quality-tests.results.update', $result), ['tested_on' => '2026-10-29', 'load_kn' => 500])->assertSessionHasErrors('strength');
        $this->patch(route('quality-tests.results.update', $result), ['tested_on' => '2026-10-29', 'strength' => '31.4'])->assertSessionHasNoErrors();

        $this->assertSame('31.40', $result->refresh()->strength);
        $this->assertNull($result->load_kn);
    }

    public function test_correcting_a_result_keeps_the_earlier_figure_in_the_audit_log(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $result = $this->cubes($w)->results->first();
        $service = app(QualityTestService::class);

        $service->recordResult($result, ['tested_on' => '2026-10-08', 'strength' => '18.00'], $w->owner);
        $service->recordResult($result->refresh(), ['tested_on' => '2026-10-08', 'strength' => '19.50'], $w->owner);

        $log = AuditLog::where('auditable_type', QualityTestResult::class)->where('auditable_id', $result->id)->where('action', 'updated')->latest('id')->first();
        $this->assertSame('18.00', $log->old_values['strength']);
        $this->assertSame('19.50', $log->new_values['strength']);
    }

    public function test_a_full_set_of_results_gives_a_verdict_on_the_register(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $test = $this->cubes($w);
        $service = app(QualityTestService::class);

        foreach ($test->results->where('age_days', 28)->values() as $i => $result) {
            $service->recordResult($result, ['tested_on' => '2026-10-29', 'strength' => ['26.0', '27.0', '25.0'][$i]], $w->owner);
        }
        foreach ($test->results->where('age_days', 7) as $result) {
            $service->recordResult($result, ['tested_on' => '2026-10-08', 'strength' => '18.0'], $w->owner);
        }

        $this->get(route('quality-tests.index', $w->project))->assertOk()->assertSee('26.00')->assertSee('Pass')->assertSee('On track');
        $this->get(route('quality-tests.show', $test))->assertOk()->assertSee('26.00')->assertSee('not a test certificate', false);
    }

    public function test_a_failing_set_is_flagged(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $test = $this->cubes($w);

        foreach ($test->results->where('age_days', 28) as $result) {
            app(QualityTestService::class)->recordResult($result, ['tested_on' => '2026-10-29', 'strength' => '21.0'], $w->owner);
        }

        $this->get(route('quality-tests.index', $w->project))->assertSee('Below grade');
    }

    public function test_specimens_due_for_testing_are_counted(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $this->cubes($w, ['cast_date' => today()->subDays(8)->toDateString()]); // the 7-day cubes are due, 28-day are not

        $this->get(route('quality-tests.index', $w->project))->assertOk()->assertSee('Specimens due for testing');
        $this->assertSame(3, QualityTestResult::whereNull('strength')->whereDate('due_on', '<=', today())->count());
    }

    public function test_extra_specimens_can_be_added(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $test = $this->cubes($w);

        $this->post(route('quality-tests.specimens.store', $test), ['age_days' => 56])->assertRedirect();
        $this->post(route('quality-tests.specimens.store', $test), ['age_days' => 400])->assertSessionHasErrors('age_days');

        $added = $test->results()->get()->last();
        $this->assertSame(56, $added->age_days);
        $this->assertSame(7, $added->specimen_no);
        $this->assertSame('2026-11-26', $added->due_on->toDateString());
    }

    public function test_registering_twice_with_the_same_client_uuid_gives_one_test(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $uuid = (string) Str::uuid();
        $payload = ['test_type' => 'cube', 'element' => 'Footing F4', 'grade' => 'M20', 'cast_date' => '2026-10-01', 'client_uuid' => $uuid];

        $this->post(route('quality-tests.store', $w->project), $payload)->assertRedirect();
        $this->post(route('quality-tests.store', $w->project), $payload)->assertRedirect();

        $this->assertSame(1, QualityTest::count());
        $this->assertSame(6, QualityTestResult::count());
    }

    public function test_input_is_checked(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);

        $this->post(route('quality-tests.store', $w->project), ['test_type' => 'magic', 'cast_date' => '2999-01-01', 'specimens_7' => 99])
            ->assertSessionHasErrors(['test_type', 'element', 'cast_date', 'specimens_7']);
        $this->assertSame(0, QualityTest::count());
    }

    public function test_who_can_register_and_record(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $test = $this->cubes($w);
        $result = $test->results->first();

        foreach (['engineer' => true, 'pm' => true, 'viewer' => false, 'accounts' => false, 'qs' => false] as $role => $canWrite) {
            Auth::forgetUser();
            $this->actingAs($this->member($w->organization, $role));

            $this->get(route('quality-tests.index', $w->project))->assertOk();
            $this->get(route('quality-tests.show', $test))->assertOk();
            $this->post(route('quality-tests.store', $w->project), ['test_type' => 'cube', 'element' => 'X', 'cast_date' => '2026-10-01'])->assertStatus($canWrite ? 302 : 403);
            $this->patch(route('quality-tests.results.update', $result), ['tested_on' => '2026-10-08', 'strength' => 20])->assertStatus($canWrite ? 302 : 403);
        }

        Auth::forgetUser();
        $this->actingAs($this->member($w->organization, 'store'))->get(route('quality-tests.index', $w->project))->assertForbidden();
    }

    public function test_another_companys_tests_are_out_of_reach(): void
    {
        $mine = $this->world();
        $theirs = $this->world('owner', 'business', 'Rival Builders');
        $this->actingAs($theirs->owner);
        $theirTest = $this->cubes($theirs);
        $theirResult = $theirTest->results->first();

        Auth::forgetUser();
        $this->actingAs($mine->owner);

        $this->get(route('quality-tests.show', $theirTest))->assertNotFound();
        $this->get(route('quality-tests.index', $theirs->project))->assertNotFound();
        $this->post(route('quality-tests.store', $theirs->project), ['test_type' => 'cube', 'element' => 'X', 'cast_date' => '2026-10-01'])->assertNotFound();
        $this->patch(route('quality-tests.results.update', $theirResult), ['tested_on' => '2026-10-08', 'strength' => 20])->assertNotFound();
        $this->post(route('quality-tests.specimens.store', $theirTest), ['age_days' => 56])->assertNotFound();
        $this->assertNull(QualityTestResult::withoutGlobalScopes()->find($theirResult->id)->strength);
        $this->assertSame(6, QualityTestResult::withoutGlobalScopes()->where('quality_test_id', $theirTest->id)->count());
    }

    public function test_a_site_from_another_project_is_refused(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $site = Site::create(['organization_id' => $w->organization->id, 'project_id' => $this->projectOf($w->organization)->id, 'name' => 'Elsewhere']);

        $this->post(route('quality-tests.store', $w->project), ['test_type' => 'cube', 'element' => 'X', 'cast_date' => '2026-10-01', 'site_id' => $site->id])->assertSessionHasErrors('site_id');
        $this->assertSame(0, QualityTest::count());
    }
}
