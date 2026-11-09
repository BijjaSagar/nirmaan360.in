<?php

namespace App\Services\Reports;

use App\Models\Attendance;
use App\Models\BoqItem;
use App\Models\Concerns\OrganizationScope;
use App\Models\MeasurementEntry;
use App\Models\Organization;
use App\Models\PettyCashEntry;
use App\Models\PettyCashWallet;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\User;
use App\Models\Worker;
use App\Support\Dec;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Builds the reports on the Reports screen from data already in the system.
 *
 * Every query filters by the organisation itself, so the same code is safe on the
 * signed-in screen and in the scheduler, where there is no signed-in user.
 */
class ReportBuilder
{
    /**
     * Most rows a report reads. A row held as a model costs several KB, so reading more would not fit in
     * memory. The Excel file and the PDF may hold fewer still. See ReportExporter.
     */
    public const MAX_ROWS = 10000;

    public const MEASUREMENT_STATUSES = ['DRAFT', 'IN_REVIEW', 'RETURNED', 'APPROVED', 'REJECTED'];

    public const BILL_STATUSES = ['DRAFT', 'IN_REVIEW', 'RETURNED', 'APPROVED', 'PAID', 'REJECTED', 'CANCELLED'];

    /**
     * What each report needs. "project" is true when a project must be chosen, "period" when dates apply.
     * Labels and notes go through __() when shown.
     */
    public const DEFINITIONS = [
        'boq_ledger' => [
            'label' => 'BOQ ledger',
            'note' => 'Each BOQ line with quantity executed, measured, approved and billed, and what is left.',
            'permission' => 'boq.view', 'feature' => null, 'project' => true, 'period' => false, 'statuses' => [],
        ],
        'measurement_register' => [
            'label' => 'Measurement register',
            'note' => 'Every measurement entry in the period with its dimensions, quantity and status.',
            'permission' => 'measurement.view', 'feature' => 'measurement', 'project' => false, 'period' => true, 'statuses' => self::MEASUREMENT_STATUSES,
        ],
        'ra_bill_register' => [
            'label' => 'RA bill register',
            'note' => 'Client and subcontractor bills with gross value, deductions, GST and net payable.',
            'permission' => 'ra_bill.view', 'feature' => 'billing', 'project' => false, 'period' => true, 'statuses' => self::BILL_STATUSES,
        ],
        'attendance_summary' => [
            'label' => 'Attendance summary',
            'note' => 'Days present, half days, absences and overtime for each worker, with wages.',
            'permission' => 'attendance.view', 'feature' => 'labour', 'project' => false, 'period' => true, 'statuses' => [],
        ],
        'petty_cash' => [
            'label' => 'Petty cash',
            'note' => 'Opening balance, top-ups, expenses and closing balance of each wallet.',
            'permission' => 'petty_cash.view', 'feature' => 'petty_cash', 'project' => false, 'period' => true, 'statuses' => [],
        ],
    ];

    /** Whether this user may run a report: the Reports permission plus that report's own data permission. */
    public function allowed(User $user, Organization $organization, string $key): bool
    {
        $definition = self::DEFINITIONS[$key] ?? null;

        return $definition !== null
            && $organization->allowsFeature('reports')
            && $user->hasPermission('report.view')
            && $user->hasPermission($definition['permission'])
            && ($definition['feature'] === null || $organization->allowsFeature($definition['feature']));
    }

    /** @return array<string, array<string,mixed>> */
    public function availableFor(User $user, Organization $organization): array
    {
        return array_filter(self::DEFINITIONS, fn ($definition, $key) => $this->allowed($user, $organization, $key), ARRAY_FILTER_USE_BOTH);
    }

    public function build(string $key, Organization $organization, ?int $projectId = null, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null, ?string $status = null): ReportResult
    {
        $definition = self::DEFINITIONS[$key] ?? throw new InvalidArgumentException("Unknown report [{$key}].");

        $project = $projectId
            ? Project::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $organization->id)->findOrFail($projectId)
            : null;

        if ($definition['project'] && ! $project) {
            throw ValidationException::withMessages(['project_id' => __('Choose a project for this report.')]);
        }

        $from ??= CarbonImmutable::today()->startOfMonth();
        $to ??= CarbonImmutable::today();

        if ($status !== null && ! in_array($status, $definition['statuses'], true)) {
            $status = null;
        }

        return match ($key) {
            'boq_ledger' => $this->boqLedger($organization, $project),
            'measurement_register' => $this->measurementRegister($organization, $project, $from, $to, $status),
            'ra_bill_register' => $this->raBillRegister($organization, $project, $from, $to, $status),
            'attendance_summary' => $this->attendanceSummary($organization, $project, $from, $to),
            'petty_cash' => $this->pettyCash($organization, $project, $from, $to),
        };
    }

    private function boqLedger(Organization $organization, Project $project): ReportResult
    {
        $items = BoqItem::withoutGlobalScope(OrganizationScope::class)->withLedger()->with('section')
            ->where('boq_items.organization_id', $organization->id)
            ->where('boq_items.project_id', $project->id)
            ->orderBy('sort')->orderBy('id')
            ->limit(static::MAX_ROWS + 1)->get();

        [$items, $truncated] = $this->trim($items);

        $totals = ['amount' => Dec::of(0), 'approved_amount' => Dec::of(0), 'billed_amount' => Dec::of(0)];
        $rows = [];

        foreach ($items as $item) {
            $approvedAmount = Dec::of($item->approved_qty ?? 0)->multipliedBy(Dec::of($item->rate));
            $billedAmount = Dec::of($item->billed_qty ?? 0)->multipliedBy(Dec::of($item->rate));
            $amount = Dec::of($item->amount());

            $totals['amount'] = $totals['amount']->plus($amount);
            $totals['approved_amount'] = $totals['approved_amount']->plus(Dec::of(Dec::money($approvedAmount)));
            $totals['billed_amount'] = $totals['billed_amount']->plus(Dec::of(Dec::money($billedAmount)));

            $rows[] = [
                'section' => $item->section?->name,
                'code' => $item->code,
                'description' => $item->description,
                'unit' => $item->unit,
                'rate' => Dec::money($item->rate),
                'qty' => Dec::qty($item->qty),
                'executed' => Dec::qty($item->executed_qty ?? 0),
                'measured' => Dec::qty($item->measured_qty ?? 0),
                'approved' => Dec::qty($item->approved_qty ?? 0),
                'billed' => Dec::qty($item->billed_qty ?? 0),
                'balance' => $item->balanceQty(),
                'amount' => Dec::money($amount),
                'approved_amount' => Dec::money($approvedAmount),
                'billed_amount' => Dec::money($billedAmount),
            ];
        }

        return new ReportResult(
            'boq_ledger',
            __('BOQ ledger'),
            $project->code.' · '.$project->name,
            [
                $this->col('section', 'Section'), $this->col('code', 'Code'), $this->col('description', 'Description'), $this->col('unit', 'Unit'),
                $this->col('rate', 'Rate', 'money'), $this->col('qty', 'BOQ qty', 'qty'), $this->col('executed', 'Executed', 'qty'),
                $this->col('measured', 'Measured', 'qty'), $this->col('approved', 'Approved', 'qty'), $this->col('billed', 'Billed', 'qty'),
                $this->col('balance', 'Balance', 'qty'), $this->col('amount', 'BOQ amount', 'money'),
                $this->col('approved_amount', 'Approved amount', 'money'), $this->col('billed_amount', 'Billed amount', 'money'),
            ],
            $rows,
            array_map(fn ($total) => Dec::money($total), $totals),
            [__('Balance is the BOQ quantity less everything measured so far, rejected entries excluded. Billed counts client bills that are not cancelled.'), ...$this->limitNote($truncated)],
            $truncated,
        );
    }

    private function measurementRegister(Organization $organization, ?Project $project, CarbonImmutable $from, CarbonImmutable $to, ?string $status): ReportResult
    {
        $entries = MeasurementEntry::withoutGlobalScope(OrganizationScope::class)->with(['boqItem', 'project' => fn ($q) => $q->withTrashed()])
            ->where('measurement_entries.organization_id', $organization->id)
            ->when($project, fn ($q) => $q->where('project_id', $project->id))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->whereDate('measured_on', '>=', $from->toDateString())
            ->whereDate('measured_on', '<=', $to->toDateString())
            ->orderBy('measured_on')->orderBy('id')
            ->limit(static::MAX_ROWS + 1)->get();

        [$entries, $truncated] = $this->trim($entries);

        $rows = $entries->map(fn (MeasurementEntry $entry) => [
            'date' => $entry->measured_on->toDateString(),
            'project' => $entry->project?->code,
            'code' => $entry->boqItem?->code,
            'item' => $entry->boqItem?->description,
            'location' => $entry->location,
            'nos' => $entry->nos === null ? null : Dec::qty($entry->nos),
            'length' => $entry->length,
            'breadth' => $entry->breadth,
            'depth' => $entry->depth,
            'qty' => Dec::qty($entry->qty),
            'unit' => $entry->boqItem?->unit,
            'status' => __(Str::headline(strtolower($entry->status))).($entry->deviation ? ' · '.__('Deviation') : ''),
            'billed' => $entry->isBilled() ? __('Yes') : __('No'),
        ])->all();

        $columns = [$this->col('date', 'Date', 'date')];
        if (! $project) {
            $columns[] = $this->col('project', 'Project');
        }
        array_push(
            $columns,
            $this->col('code', 'Item'), $this->col('item', 'Description'), $this->col('location', 'Location'),
            $this->col('nos', 'Nos', 'qty'), $this->col('length', 'L', 'qty'), $this->col('breadth', 'B', 'qty'), $this->col('depth', 'D', 'qty'),
            $this->col('qty', 'Quantity', 'qty'), $this->col('unit', 'Unit'), $this->col('status', 'Status'), $this->col('billed', 'Billed'),
        );

        return new ReportResult(
            'measurement_register',
            __('Measurement register'),
            $this->scope($project, $from, $to).($status ? ' · '.__(Str::headline(strtolower($status))) : ''),
            $columns,
            $rows,
            null,
            [__('Deductions and reversals are negative quantities. Quantities of different units are not added up.'), ...$this->limitNote($truncated)],
            $truncated,
        );
    }

    private function raBillRegister(Organization $organization, ?Project $project, CarbonImmutable $from, CarbonImmutable $to, ?string $status): ReportResult
    {
        // A bill belongs to the period if its billing period overlaps the dates asked for.
        $bills = RaBill::withoutGlobalScope(OrganizationScope::class)
            ->with(['project' => fn ($q) => $q->withTrashed(), 'party' => fn ($q) => $q->withTrashed()])
            ->where('ra_bills.organization_id', $organization->id)
            ->when($project, fn ($q) => $q->where('project_id', $project->id))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->whereDate('period_to', '>=', $from->toDateString())
            ->whereDate('period_from', '<=', $to->toDateString())
            ->orderBy('period_to')->orderBy('id')
            ->limit(static::MAX_ROWS + 1)->get();

        [$bills, $truncated] = $this->trim($bills);

        $totals = ['gross' => Dec::of(0), 'deductions' => Dec::of(0), 'gst' => Dec::of(0), 'net' => Dec::of(0)];
        $rows = [];

        foreach ($bills as $bill) {
            if ($bill->status !== 'CANCELLED') {
                $totals['gross'] = $totals['gross']->plus(Dec::of($bill->gross_amount));
                $totals['deductions'] = $totals['deductions']->plus(Dec::of($bill->deduction_total));
                $totals['gst'] = $totals['gst']->plus(Dec::of($bill->gst_amount));
                $totals['net'] = $totals['net']->plus(Dec::of($bill->net_payable));
            }

            $rows[] = [
                'number' => $bill->number,
                'project' => $bill->project?->code,
                'type' => $bill->type === 'client' ? __('Client') : __('Subcontractor'),
                'party' => $bill->party?->name,
                'period_from' => $bill->period_from->toDateString(),
                'period_to' => $bill->period_to->toDateString(),
                'status' => __(Str::headline(strtolower($bill->status))),
                'gross' => Dec::money($bill->gross_amount),
                'deductions' => Dec::money($bill->deduction_total),
                'gst' => Dec::money($bill->gst_amount),
                'net' => Dec::money($bill->net_payable),
                'paid_on' => $bill->paid_at?->toDateString(),
            ];
        }

        return new ReportResult(
            'ra_bill_register',
            __('RA bill register'),
            $this->scope($project, $from, $to).($status ? ' · '.__(Str::headline(strtolower($status))) : ''),
            [
                $this->col('number', 'Bill'), $this->col('project', 'Project'), $this->col('type', 'Type'), $this->col('party', 'Party'),
                $this->col('period_from', 'From', 'date'), $this->col('period_to', 'To', 'date'), $this->col('status', 'Status'),
                $this->col('gross', 'Gross', 'money'), $this->col('deductions', 'Deductions', 'money'), $this->col('gst', 'GST', 'money'),
                $this->col('net', 'Net payable', 'money'), $this->col('paid_on', 'Paid on', 'date'),
            ],
            $rows,
            array_map(fn ($total) => Dec::money($total), $totals),
            [__('Cancelled bills are listed but not added to the totals.'), ...$this->limitNote($truncated)],
            $truncated,
        );
    }

    private function attendanceSummary(Organization $organization, ?Project $project, CarbonImmutable $from, CarbonImmutable $to): ReportResult
    {
        $marks = Attendance::withoutGlobalScopes()
            ->where('attendance.organization_id', $organization->id)
            ->when($project, fn ($q) => $q->where('project_id', $project->id))
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $to->toDateString())
            ->selectRaw('worker_id, status, count(*) as days, sum(ot_hours) as ot')
            ->groupBy('worker_id', 'status')
            ->get();

        $workers = Worker::withoutGlobalScope(OrganizationScope::class)->withTrashed()->with('contractor')
            ->where('workers.organization_id', $organization->id)
            ->whereIn('id', $marks->pluck('worker_id')->unique())
            ->get()->sortBy(fn (Worker $worker) => mb_strtolower($worker->name));

        $totals = ['present' => 0, 'half' => 0, 'absent' => 0, 'ot' => Dec::of(0), 'days' => Dec::of(0), 'wages' => Dec::of(0)];
        $rows = [];

        foreach ($workers as $worker) {
            $mine = $marks->where('worker_id', $worker->id);
            $count = fn (string $status) => (int) $mine->where('status', $status)->sum('days');

            // A day marked Overtime is a full working day with extra hours.
            $present = $count('present') + $count('overtime');
            $half = $count('half');
            $absent = $count('absent');
            $ot = $mine->reduce(fn ($sum, $mark) => $sum->plus(Dec::of($mark->ot ?? 0)), Dec::of(0));
            $days = Dec::of($present)->plus(Dec::of($half)->dividedBy(2, 1));
            $wages = $days->multipliedBy(Dec::of($worker->daily_wage));

            $totals['present'] += $present;
            $totals['half'] += $half;
            $totals['absent'] += $absent;
            $totals['ot'] = $totals['ot']->plus($ot);
            $totals['days'] = $totals['days']->plus($days);
            $totals['wages'] = $totals['wages']->plus(Dec::of(Dec::money($wages)));

            $rows[] = [
                'worker' => $worker->name,
                'trade' => __($worker->trade),
                'type' => $worker->type === 'own' || ! $worker->contractor ? __('Own') : $worker->contractor->name,
                'present' => $present,
                'half' => $half,
                'absent' => $absent,
                'ot' => (string) $ot->toScale(1, RoundingMode::HalfUp),
                'days' => (string) $days->toScale(1, RoundingMode::HalfUp),
                'wage' => Dec::money($worker->daily_wage),
                'wages' => Dec::money($wages),
            ];
        }

        return new ReportResult(
            'attendance_summary',
            __('Attendance summary'),
            $this->scope($project, $from, $to),
            [
                $this->col('worker', 'Worker'), $this->col('trade', 'Trade'), $this->col('type', 'Type'),
                $this->col('present', 'Present', 'int'), $this->col('half', 'Half days', 'int'), $this->col('absent', 'Absent', 'int'),
                $this->col('ot', 'OT hours', 'dec1'), $this->col('days', 'Days payable', 'dec1'),
                $this->col('wage', 'Daily wage', 'money'), $this->col('wages', 'Wages', 'money'),
            ],
            $rows,
            [
                'present' => $totals['present'], 'half' => $totals['half'], 'absent' => $totals['absent'],
                'ot' => (string) $totals['ot']->toScale(1, RoundingMode::HalfUp), 'days' => (string) $totals['days']->toScale(1, RoundingMode::HalfUp), 'wages' => Dec::money($totals['wages']),
            ],
            [__('Days marked Overtime count as full days. A half day counts as 0.5. Overtime hours are shown but not priced.')],
        );
    }

    private function pettyCash(Organization $organization, ?Project $project, CarbonImmutable $from, CarbonImmutable $to): ReportResult
    {
        $wallets = PettyCashWallet::withoutGlobalScopes()->with('holder')
            ->where('petty_cash_wallets.organization_id', $organization->id)
            ->when($project, fn ($q) => $q->where('project_id', $project->id))
            ->orderBy('project_id')->orderBy('id')
            ->get();

        $ids = $wallets->pluck('id');
        $sums = fn ($query) => $query->selectRaw('petty_cash_wallet_id, type, sum(amount) as total')->groupBy('petty_cash_wallet_id', 'type')->get();

        $before = $sums(PettyCashEntry::withoutGlobalScopes()->where('organization_id', $organization->id)->whereIn('petty_cash_wallet_id', $ids)
            ->whereDate('entry_date', '<', $from->toDateString()));
        $within = $sums(PettyCashEntry::withoutGlobalScopes()->where('organization_id', $organization->id)->whereIn('petty_cash_wallet_id', $ids)
            ->whereDate('entry_date', '>=', $from->toDateString())->whereDate('entry_date', '<=', $to->toDateString()));

        $projects = Project::withoutGlobalScope(OrganizationScope::class)->withTrashed()
            ->where('organization_id', $organization->id)->whereIn('id', $wallets->pluck('project_id')->unique())->get()->keyBy('id');

        // Rows are one per wallet and type, so there is nothing to add up in PHP.
        $total = fn ($rows, int $wallet, string $type) => Dec::of(Dec::money($rows->first(fn ($row) => (int) $row->petty_cash_wallet_id === $wallet && $row->type === $type)?->total ?? 0));

        $totals = ['opening' => Dec::of(0), 'topups' => Dec::of(0), 'expenses' => Dec::of(0), 'closing' => Dec::of(0)];
        $rows = [];

        foreach ($wallets as $wallet) {
            $opening = $total($before, $wallet->id, 'topup')->minus($total($before, $wallet->id, 'expense'));
            $topups = $total($within, $wallet->id, 'topup');
            $expenses = $total($within, $wallet->id, 'expense');
            $closing = $opening->plus($topups)->minus($expenses);

            // A wallet with no balance and no movement adds only noise.
            if ($opening->isZero() && $topups->isZero() && $expenses->isZero()) {
                continue;
            }

            $totals['opening'] = $totals['opening']->plus($opening);
            $totals['topups'] = $totals['topups']->plus($topups);
            $totals['expenses'] = $totals['expenses']->plus($expenses);
            $totals['closing'] = $totals['closing']->plus($closing);

            $rows[] = [
                'project' => $projects->get($wallet->project_id)?->code,
                'wallet' => $wallet->name,
                'holder' => $wallet->holder?->name,
                'opening' => Dec::money($opening),
                'topups' => Dec::money($topups),
                'expenses' => Dec::money($expenses),
                'closing' => Dec::money($closing),
            ];
        }

        return new ReportResult(
            'petty_cash',
            __('Petty cash'),
            $this->scope($project, $from, $to),
            [
                $this->col('project', 'Project'), $this->col('wallet', 'Wallet'), $this->col('holder', 'Holder'),
                $this->col('opening', 'Opening', 'money'), $this->col('topups', 'Top-ups', 'money'),
                $this->col('expenses', 'Expenses', 'money'), $this->col('closing', 'Closing', 'money'),
            ],
            $rows,
            array_map(fn ($value) => Dec::money($value), $totals),
            [__('Wallets with no balance and no entries in the period are left out.')],
        );
    }

    /** @return list<string> */
    private function limitNote(bool $truncated): array
    {
        return $truncated ? [__('Only the first :max rows were read, and the totals cover only those rows. Narrow the dates or the project to see the rest.', ['max' => number_format(static::MAX_ROWS)])] : [];
    }

    /** @return array{key:string,label:string,type:string} */
    private function col(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => __($label), 'type' => $type];
    }

    /** "Riverside Tower · 01 Nov 2026 to 30 Nov 2026" */
    private function scope(?Project $project, CarbonImmutable $from, CarbonImmutable $to): string
    {
        return ($project ? $project->code.' · '.$project->name : __('All projects')).' · '.ReportPeriod::label($from, $to);
    }

    /**
     * @template T
     *
     * @param  Collection<int, T>  $rows
     * @return array{0: Collection<int, T>, 1: bool}
     */
    private function trim($rows): array
    {
        return $rows->count() > static::MAX_ROWS ? [$rows->take(static::MAX_ROWS), true] : [$rows, false];
    }
}
