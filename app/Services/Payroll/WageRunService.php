<?php

namespace App\Services\Payroll;

use App\Exceptions\ImmutableRecordException;
use App\Models\AdvanceRecovery;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Models\WageRun;
use App\Models\WageRunLine;
use App\Models\Worker;
use App\Models\WorkerAdvance;
use App\Support\Dec;
use App\Support\Idempotent;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds wage runs from attendance, takes advances back, and locks a run once it is approved.
 * Every query names the organisation, so the service is safe to call from the console and from tests.
 */
class WageRunService
{
    public const SCOPES = ['all', 'own', 'contractor'];

    public function __construct(private WageCalculator $calculator) {}

    /** Overtime and shift terms for a company. A run keeps its own copy, so a later change never alters a past run. */
    public function terms(Organization $organization): array
    {
        $saved = $organization->settings['payroll'] ?? [];

        return [
            'shift_hours' => (string) ($saved['shift_hours'] ?? 8),
            'ot_multiplier' => (string) ($saved['ot_multiplier'] ?? 2),
        ];
    }

    public function saveTerms(Organization $organization, array $terms): void
    {
        $old = $this->terms($organization);
        $settings = $organization->settings ?? [];
        $plain = fn (mixed $value) => rtrim(rtrim(Dec::money($value), '0'), '.');
        $settings['payroll'] = [
            'shift_hours' => $plain($terms['shift_hours']),
            'ot_multiplier' => $plain($terms['ot_multiplier']),
        ];

        $organization->update(['settings' => $settings]);
        AuditLog::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'user_id' => Auth::id(),
            'action' => 'payroll.terms',
            'auditable_type' => $organization->getMorphClass(),
            'auditable_id' => $organization->id,
            'old_values' => $old,
            'new_values' => $settings['payroll'],
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * Create a run for the period and calculate it. Raises a validation error when the period
     * overlaps another run or has nothing to pay. A repeated client_uuid returns the original run.
     *
     * @param  array{period_from:string, period_to:string, project_id?:?int, worker_scope?:?string, notes?:?string, client_uuid?:?string}  $data
     */
    public function create(array $data, User $by): WageRun
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findByClientUuid($by->organization_id, $uuid))) {
            return $existing;
        }

        return Idempotent::create(function () use ($data, $by) {
            // One run is created at a time per company, so numbers and the overlap check cannot race.
            $organization = Organization::whereKey($by->organization_id)->lockForUpdate()->firstOrFail();
            $scope = $data['worker_scope'] ?? 'all';
            $projectId = $data['project_id'] ?? null;

            $this->assertNoOverlap($organization->id, $data['period_from'], $data['period_to'], $projectId, $scope);

            $run = WageRun::create([
                'organization_id' => $organization->id,
                'number' => $this->nextNumber($organization->id, $data['period_to']),
                'project_id' => $projectId,
                'worker_scope' => $scope,
                'period_from' => $data['period_from'],
                'period_to' => $data['period_to'],
                'terms' => $this->terms($organization),
                'notes' => $data['notes'] ?? null,
                'prepared_by' => $by->id,
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);

            $this->calculate($run);

            return $run->refresh();
        }, fn () => $this->findByClientUuid($by->organization_id, (string) $uuid));
    }

    /** Throw the lines away and calculate again from today's attendance. Only drafts. */
    public function recalculate(WageRun $run): WageRun
    {
        return DB::transaction(function () use ($run) {
            $run = WageRun::withoutGlobalScopes()->lockForUpdate()->findOrFail($run->id);
            $this->calculate($run);

            return $run->refresh();
        });
    }

    /** Whether attendance was marked or changed after this run was calculated. */
    public function isStale(WageRun $run): bool
    {
        return $run->status === 'DRAFT'
            && $this->attendanceFor($run)->where('updated_at', '>', $run->updated_at)->exists();
    }

    public function approve(WageRun $run, User $by): WageRun
    {
        return DB::transaction(function () use ($run, $by) {
            $run = WageRun::withoutGlobalScopes()->lockForUpdate()->findOrFail($run->id);

            if ($run->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => __('Only a draft wage run can be approved.')]);
            }

            if ($run->prepared_by === $by->id && ! $by->isApprovalOverride()) {
                throw ValidationException::withMessages(['status' => __('A different person must approve this wage run.')]);
            }

            if ($this->isStale($run)) {
                throw ValidationException::withMessages(['status' => __('Attendance changed after this run was calculated. Recalculate it first.')]);
            }

            $run->update(['status' => 'APPROVED', 'approved_by' => $by->id, 'approved_at' => now()]);
            AuditLog::record('wage_run.approve', $run, null, ['net' => $run->total_net]);

            return $run->refresh();
        });
    }

    public function markPaid(WageRun $run, User $by, ?string $note = null): WageRun
    {
        return DB::transaction(function () use ($run, $by, $note) {
            $run = WageRun::withoutGlobalScopes()->lockForUpdate()->findOrFail($run->id);

            if ($run->status !== 'APPROVED') {
                throw ValidationException::withMessages(['status' => __('Only an approved wage run can be marked as paid.')]);
            }

            $run->update(['status' => 'PAID', 'paid_at' => now(), 'payment_note' => $note]);
            AuditLog::record('wage_run.paid', $run, null, ['by' => $by->id, 'note' => $note]);

            return $run->refresh();
        });
    }

    /** Remove a draft. Its planned advance recoveries go with it, so the advances are free again. */
    public function delete(WageRun $run): void
    {
        DB::transaction(function () use ($run) {
            $run = WageRun::withoutGlobalScopes()->lockForUpdate()->findOrFail($run->id);

            if ($run->isLocked()) {
                throw new ImmutableRecordException;
            }

            $this->clear($run);
            $run->delete();
        });
    }

    /** Fill the run's lines and recoveries from attendance. */
    private function calculate(WageRun $run): void
    {
        if ($run->isLocked()) {
            throw new ImmutableRecordException;
        }

        $this->clear($run);

        $marks = $this->attendanceFor($run)->get()->groupBy('worker_id');
        $workers = Worker::withoutGlobalScopes()
            ->where('organization_id', $run->organization_id)
            ->whereIn('id', $marks->keys())
            ->when($run->worker_scope !== 'all', fn ($query) => $query->where('type', $run->worker_scope))
            ->orderBy('name')
            ->get();

        $lines = collect();

        foreach ($workers as $worker) {
            $tally = $this->calculator->tally($marks[$worker->id]);
            $pay = $this->calculator->pay($worker->daily_wage, $tally, $run->terms);

            if (Dec::of($pay['gross'])->isZero()) {
                continue;
            }

            $lines->push(WageRunLine::create([
                'organization_id' => $run->organization_id,
                'wage_run_id' => $run->id,
                'worker_id' => $worker->id,
                'worker_name' => $worker->name,
                'trade' => $worker->trade,
                'worker_type' => $worker->type,
                'daily_wage' => $worker->daily_wage,
                'present_days' => $tally['present'],
                'half_days' => $tally['half'],
                'absent_days' => $tally['absent'],
                'payable_days' => $tally['payable'],
                'ot_hours' => $tally['ot_hours'],
                'day_pay' => $pay['day_pay'],
                'ot_pay' => $pay['ot_pay'],
                'gross' => $pay['gross'],
                'net' => $pay['gross'],
            ]));
        }

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['period_from' => __('No attendance to pay in this period for the chosen workers.')]);
        }

        $this->planRecoveries($run, $lines->all());

        $lines = WageRunLine::withoutGlobalScopes()->where('wage_run_id', $run->id)->get();
        $run->update([
            'total_days' => Dec::qty($lines->reduce(fn ($sum, $line) => $sum->plus(Dec::of($line->payable_days)), Dec::of(0))),
            'total_gross' => Dec::money($lines->reduce(fn ($sum, $line) => $sum->plus(Dec::of($line->gross)), Dec::of(0))),
            'total_recovery' => Dec::money($lines->reduce(fn ($sum, $line) => $sum->plus(Dec::of($line->advance_recovery)), Dec::of(0))),
            'total_net' => Dec::money($lines->reduce(fn ($sum, $line) => $sum->plus(Dec::of($line->net)), Dec::of(0))),
        ]);
        $run->touch();
    }

    /**
     * Take advances back, oldest first. Each advance gives at most its instalment, and a worker's
     * net pay never goes below zero: what cannot be taken now stays outstanding for the next run.
     *
     * @param  list<WageRunLine>  $lines
     */
    private function planRecoveries(WageRun $run, array $lines): void
    {
        $advances = WorkerAdvance::withoutGlobalScopes()
            ->where('organization_id', $run->organization_id)
            ->whereIn('worker_id', array_map(fn ($line) => $line->worker_id, $lines))
            ->whereDate('given_on', '<=', $run->period_to->toDateString())
            ->withSum('recoveries as recovered_amount', 'amount')
            ->orderBy('given_on')
            ->orderBy('id')
            ->get()
            ->groupBy('worker_id');

        foreach ($lines as $line) {
            $room = Dec::of($line->gross);
            $taken = Dec::of(0);

            foreach ($advances->get($line->worker_id, []) as $advance) {
                $outstanding = Dec::of($advance->amount)->minus(Dec::of($advance->recovered_amount ?? 0));
                $instalment = Dec::of($advance->recovery_per_run)->isPositive()
                    ? $this->lesser($outstanding, Dec::of($advance->recovery_per_run))
                    : $outstanding;
                $take = $this->lesser($instalment, $room);

                if (! $take->isPositive()) {
                    continue;
                }

                AdvanceRecovery::create([
                    'organization_id' => $run->organization_id,
                    'worker_advance_id' => $advance->id,
                    'wage_run_id' => $run->id,
                    'wage_run_line_id' => $line->id,
                    'amount' => Dec::money($take),
                ]);

                $room = $room->minus($take);
                $taken = $taken->plus($take);
            }

            $line->update(['advance_recovery' => Dec::money($taken), 'net' => Dec::money($room)]);
        }
    }

    private function lesser(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->isLessThan($b) ? $a : $b;
    }

    private function clear(WageRun $run): void
    {
        AdvanceRecovery::withoutGlobalScopes()->where('wage_run_id', $run->id)->delete();
        WageRunLine::withoutGlobalScopes()->where('wage_run_id', $run->id)->delete();
    }

    private function attendanceFor(WageRun $run): Builder
    {
        return Attendance::withoutGlobalScopes()
            ->where('organization_id', $run->organization_id)
            ->whereDate('attendance_date', '>=', $run->period_from->toDateString())
            ->whereDate('attendance_date', '<=', $run->period_to->toDateString())
            ->when($run->project_id, fn ($query) => $query->where('project_id', $run->project_id))
            ->when($run->worker_scope !== 'all', fn ($query) => $query->whereIn(
                'worker_id',
                Worker::withoutGlobalScopes()->where('organization_id', $run->organization_id)->where('type', $run->worker_scope)->select('id')
            ));
    }

    /**
     * A day of attendance can only be paid once. Two runs may share dates only when they cover
     * different projects, or different kinds of worker.
     */
    private function assertNoOverlap(int $organizationId, string $from, string $to, ?int $projectId, string $scope): void
    {
        $clash = WageRun::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereDate('period_from', '<=', $to)
            ->whereDate('period_to', '>=', $from)
            ->when($projectId, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('project_id')->orWhere('project_id', $projectId)))
            ->when($scope !== 'all', fn ($query) => $query->whereIn('worker_scope', ['all', $scope]))
            ->first();

        if ($clash) {
            throw ValidationException::withMessages([
                'period_from' => __('This period overlaps wage run :number, so some days would be paid twice.', ['number' => $clash->number]),
            ]);
        }
    }

    /** WR/2026-27/0001. The financial year runs April to March. */
    private function nextNumber(int $organizationId, string $periodTo): string
    {
        $date = Carbon::parse($periodTo);
        $start = $date->month >= 4 ? $date->year : $date->year - 1;
        $prefix = sprintf('WR/%d-%02d/', $start, ($start + 1) % 100);

        $last = WageRun::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('number', 'like', $prefix.'%')
            ->pluck('number')
            ->map(fn ($number) => (int) substr($number, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    private function findByClientUuid(int $organizationId, string $uuid): ?WageRun
    {
        return WageRun::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }
}
