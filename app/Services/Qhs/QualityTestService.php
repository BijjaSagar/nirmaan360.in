<?php

namespace App\Services\Qhs;

use App\Models\Project;
use App\Models\QualityTest;
use App\Models\QualityTestResult;
use App\Models\User;
use App\Services\FieldOps\CreatesOnce;
use App\Support\Dec;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The quality test register: samples cast on site and the results that come back from the lab. */
class QualityTestService
{
    use CreatesOnce;

    private const FIELDS = ['test_type', 'sample_ref', 'element', 'grade', 'cast_date', 'supplier', 'cast_by', 'notes', 'site_id'];

    /** Concrete tests get the usual set: three specimens at 7 days and three at 28. */
    private const DEFAULT_SPECIMENS = 3;

    public function rules(Project $project): array
    {
        return [
            'test_type' => ['required', Rule::in(array_keys(config('modules.qhs.test_types')))],
            'sample_ref' => ['nullable', 'string', 'max:60'],
            'element' => ['required', 'string', 'max:160'],
            'grade' => ['nullable', 'string', 'max:20'],
            'required_strength' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'cast_date' => ['required', 'date', 'before_or_equal:today'],
            'quantity_cum' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'supplier' => ['nullable', 'string', 'max:120'],
            'cast_by' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'site_id' => ['bail', 'nullable', 'integer', $this->siteOfProjectRule($project->id)],
            'specimens_7' => ['nullable', 'integer', 'min:0', 'max:12'],
            'specimens_28' => ['nullable', 'integer', 'min:0', 'max:12'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function resultRules(): array
    {
        return [
            'tested_on' => ['required', 'date', 'before_or_equal:today'],
            'load_kn' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'strength' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function specimenRules(): array
    {
        return ['age_days' => ['required', 'integer', 'min:0', 'max:365']];
    }

    /** Register a sample batch and schedule its specimens. A repeated client_uuid returns the original. */
    public function create(Project $project, array $data, User $user): QualityTest
    {
        return $this->createOnce(QualityTest::class, $project->organization_id, $data['client_uuid'] ?? null, function () use ($project, $data, $user) {
            $concrete = in_array($data['test_type'], ['cube', 'cylinder'], true);

            $test = QualityTest::create([
                ...Arr::only($data, self::FIELDS),
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'required_strength' => filled($data['required_strength'] ?? null)
                    ? Dec::money($data['required_strength'])
                    : ConcreteStrength::gradeStrength($data['grade'] ?? null),
                'quantity_cum' => filled($data['quantity_cum'] ?? null) ? Dec::qty($data['quantity_cum']) : null,
                'created_by' => $user->id,
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);

            $schedule = [
                7 => (int) ($data['specimens_7'] ?? ($concrete ? self::DEFAULT_SPECIMENS : 0)),
                28 => (int) ($data['specimens_28'] ?? ($concrete ? self::DEFAULT_SPECIMENS : 0)),
            ];

            foreach ($schedule as $age => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $this->addSpecimen($test, $age);
                }
            }

            return $test;
        });
    }

    /** Schedule one more specimen, for example one extra at 56 days. */
    public function addSpecimen(QualityTest $test, int $age): QualityTestResult
    {
        return QualityTestResult::create([
            'organization_id' => $test->organization_id,
            'quality_test_id' => $test->id,
            'specimen_no' => (int) QualityTestResult::where('quality_test_id', $test->id)->max('specimen_no') + 1,
            'age_days' => $age,
            'due_on' => Carbon::parse($test->cast_date)->addDays($age)->toDateString(),
        ]);
    }

    /**
     * Record what the lab found. A cube load is turned into a strength; other tests need the strength entered.
     * Entering a result again corrects it, and the audit log keeps the earlier figure.
     */
    public function recordResult(QualityTestResult $result, array $data, User $user): QualityTestResult
    {
        $test = $result->test;
        $load = $data['load_kn'] ?? null;
        $strength = $data['strength'] ?? null;

        if (blank($load) && blank($strength)) {
            throw ValidationException::withMessages(['strength' => __('Enter the crushing load or the strength.')]);
        }

        if (Carbon::parse($data['tested_on'])->lt($test->cast_date)) {
            throw ValidationException::withMessages(['tested_on' => __('A specimen cannot be tested before it was cast.')]);
        }

        if (filled($load) && $test->test_type === 'cube') {
            $strength = ConcreteStrength::fromCubeLoad($load);
        } elseif (blank($strength)) {
            throw ValidationException::withMessages(['strength' => __('Enter the strength in N/mm² for this type of test.')]);
        }

        $result->update([
            'tested_on' => $data['tested_on'],
            'load_kn' => filled($load) ? Dec::money($load) : null,
            'strength' => Dec::money($strength),
            'remarks' => $data['remarks'] ?? null,
            'recorded_by' => $user->id,
        ]);

        return $result;
    }
}
