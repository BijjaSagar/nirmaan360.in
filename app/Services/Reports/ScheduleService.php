<?php

namespace App\Services\Reports;

use App\Mail\ScheduledReportMail;
use App\Models\AuditLog;
use App\Models\Concerns\OrganizationScope;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Support\Idempotent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Saved schedules that email a report. The Laravel scheduler runs `reports:send-scheduled` every
 * fifteen minutes, and each schedule fires once when its time has passed.
 *
 * A schedule runs as the person who saved it. If they have left, lost access to the report, or the
 * plan no longer includes reports, it is skipped. Nothing is sent unless email is set up.
 */
class ScheduleService
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    public const MAX_RECIPIENTS = 5;

    public const MAX_PER_ORGANIZATION = 25;

    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    public function __construct(
        private ReportBuilder $builder,
        private ReportExporter $exporter,
        private ReportMailGateway $mail,
    ) {}

    public function rules(User $user): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'report' => ['required', Rule::in(array_keys(ReportBuilder::DEFINITIONS))],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('organization_id', $user->organization_id)->whereNull('deleted_at')],
            'period' => ['nullable', Rule::in(ReportPeriod::SCHEDULABLE)],
            'format' => ['required', Rule::in(['xlsx', 'pdf'])],
            'frequency' => ['required', Rule::in(self::FREQUENCIES)],
            'day_of_week' => ['nullable', 'required_if:frequency,weekly', 'integer', 'between:1,7'],
            'day_of_month' => ['nullable', 'required_if:frequency,monthly', 'integer', 'between:1,28'],
            'hour' => ['required', 'integer', 'between:0,23'],
            'recipients' => ['required', 'array', 'min:1', 'max:'.self::MAX_RECIPIENTS],
            'recipients.*' => ['email:rfc', 'max:190'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** Split "a@x.com, b@y.com" into a list of unique addresses. */
    public function recipients(string $raw): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($email) => mb_strtolower(trim($email)),
            preg_split('/[\s,;]+/', $raw) ?: [],
        ))));
    }

    /** Save a schedule. A repeated client_uuid returns the original row. */
    public function create(User $user, array $data): ReportSchedule
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findByClientUuid($user->organization_id, $uuid))) {
            return $existing;
        }

        $organization = $user->organization;

        if (! $this->builder->allowed($user, $organization, $data['report'])) {
            throw ValidationException::withMessages(['report' => __('You cannot schedule this report.')]);
        }

        if (ReportBuilder::DEFINITIONS[$data['report']]['project'] && empty($data['project_id'])) {
            throw ValidationException::withMessages(['project_id' => __('Choose a project for this report.')]);
        }

        if (ReportSchedule::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $user->organization_id)->count() >= self::MAX_PER_ORGANIZATION) {
            throw ValidationException::withMessages(['report' => __('You can keep up to :max schedules. Delete one first.', ['max' => self::MAX_PER_ORGANIZATION])]);
        }

        return Idempotent::create(fn () => ReportSchedule::create([
            'organization_id' => $user->organization_id,
            'client_uuid' => $uuid,
            'created_by' => $user->id,
            'name' => filled($data['name'] ?? null) ? trim($data['name']) : null,
            'report' => $data['report'],
            'project_id' => $data['project_id'] ?? null,
            'period' => $data['period'] ?? 'last_30_days',
            'format' => $data['format'],
            'frequency' => $data['frequency'],
            'day_of_week' => $data['frequency'] === 'weekly' ? (int) $data['day_of_week'] : null,
            'day_of_month' => $data['frequency'] === 'monthly' ? (int) $data['day_of_month'] : null,
            'hour' => (int) $data['hour'],
            'recipients' => array_values($data['recipients']),
            'active' => true,
        ]), fn () => $this->findByClientUuid($user->organization_id, (string) $uuid));
    }

    private function findByClientUuid(int $organizationId, string $uuid): ?ReportSchedule
    {
        return ReportSchedule::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }

    /** "Every Monday at 07:00" */
    public function describe(ReportSchedule $schedule): string
    {
        $time = sprintf('%02d:00', $schedule->hour);

        return match ($schedule->frequency) {
            'weekly' => __('Every :day at :time', ['day' => __(self::DAYS[$schedule->day_of_week] ?? 'Monday'), 'time' => $time]),
            'monthly' => __('Day :day of every month at :time', ['day' => $schedule->day_of_month, 'time' => $time]),
            default => __('Every day at :time', ['time' => $time]),
        };
    }

    /** The most recent time this schedule was meant to fire, at or before now. */
    public function latestSlot(ReportSchedule $schedule, CarbonInterface $now): CarbonImmutable
    {
        $now = CarbonImmutable::instance($now)->setTimezone(config('app.timezone'));
        $hour = (int) $schedule->hour;

        if ($schedule->frequency === 'weekly') {
            $slot = $now->startOfWeek(CarbonInterface::MONDAY)->addDays(($schedule->day_of_week ?: 1) - 1)->setTime($hour, 0);

            return $slot->greaterThan($now) ? $slot->subWeek() : $slot;
        }

        if ($schedule->frequency === 'monthly') {
            $day = min(28, max(1, (int) $schedule->day_of_month));
            $slot = $now->startOfMonth()->addDays($day - 1)->setTime($hour, 0);

            return $slot->greaterThan($now) ? $now->subMonthNoOverflow()->startOfMonth()->addDays($day - 1)->setTime($hour, 0) : $slot;
        }

        $slot = $now->startOfDay()->setTime($hour, 0);

        return $slot->greaterThan($now) ? $slot->subDay() : $slot;
    }

    /** A schedule is due when its latest slot is later than the last run, or than its creation if it never ran. */
    public function isDue(ReportSchedule $schedule, CarbonInterface $now): bool
    {
        return $this->latestSlot($schedule, $now)->greaterThan($schedule->last_run_at ?? $schedule->created_at);
    }

    /** @return array{sent:int, waiting:int, skipped:int, failed:int} */
    public function runDue(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $counts = ['sent' => 0, 'waiting' => 0, 'skipped' => 0, 'failed' => 0];

        ReportSchedule::withoutGlobalScope(OrganizationScope::class)->where('active', true)->orderBy('id')->each(function (ReportSchedule $schedule) use ($now, &$counts) {
            if (! $this->isDue($schedule, $now)) {
                return;
            }

            $counts[match ($this->run($schedule, $now)) {
                'sent' => 'sent',
                'no_mail' => 'waiting',
                'failed' => 'failed',
                default => 'skipped',
            }]++;

            gc_collect_cycles();
        });

        return $counts;
    }

    /** Send one schedule now. Returns sent, failed, no_mail, no_owner, no_access, no_project or skipped, and saves all but the last on the schedule. */
    public function run(ReportSchedule $schedule, CarbonInterface $now): string
    {
        if (! $this->mail->configured()) {
            return $this->mark($schedule, 'no_mail', false);
        }

        $organization = Organization::find($schedule->organization_id);
        $user = User::find($schedule->created_by);

        if (! $organization || ! $user || $user->organization_id !== $organization->id || ! $user->is_active) {
            return $this->mark($schedule, 'no_owner', true);
        }

        if (! $this->builder->allowed($user, $organization, $schedule->report)) {
            return $this->mark($schedule, 'no_access', true);
        }

        if ($schedule->project_id && ! Project::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $organization->id)->whereKey($schedule->project_id)->exists()) {
            return $this->mark($schedule, 'no_project', true);
        }

        // Take this slot before sending. If another scheduler process got there first, stand down, so a report is never sent twice.
        if (! $this->claim($schedule)) {
            return 'skipped';
        }

        $locale = App::getLocale();
        App::setLocale($user->locale ?: config('app.locale'));

        try {
            [$from, $to] = ReportPeriod::resolve($schedule->period, null, null, $now);
            $report = $this->builder->build($schedule->report, $organization, $schedule->project_id, $from, $to);
            $pdf = $schedule->format === 'pdf';

            $this->mail->send($schedule->recipients, new ScheduledReportMail(
                $report->title,
                $report->subtitle,
                $organization->name,
                $report->rowCount(),
                $this->exporter->filename($report, $schedule->format),
                $pdf ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $pdf ? $this->exporter->pdf($report, $organization) : $this->exporter->xlsx($report, $organization),
                route('reports.schedules.index'),
            ));

            AuditLog::record('report.emailed', $schedule, null, ['report' => $schedule->report, 'recipients' => count($schedule->recipients), 'format' => $schedule->format]);

            return $this->mark($schedule, 'sent', true);
        } catch (Throwable $exception) {
            Log::warning('Scheduled report failed', ['schedule' => $schedule->id, 'error' => $exception->getMessage()]);

            return $this->mark($schedule, 'failed', true);
        } finally {
            App::setLocale($locale);
        }
    }

    private function claim(ReportSchedule $schedule): bool
    {
        return ReportSchedule::withoutGlobalScope(OrganizationScope::class)
            ->whereKey($schedule->id)
            ->when(
                $schedule->last_run_at,
                fn ($query, $at) => $query->where('last_run_at', $at->toDateTimeString()),
                fn ($query) => $query->whereNull('last_run_at'),
            )
            ->update(['last_run_at' => now(), 'last_status' => 'sending']) === 1;
    }

    /**
     * Save how the last attempt went. Bookkeeping is saved quietly, so it does not fill the audit log.
     * A failed send counts as handled for this slot and is tried again at the next one.
     */
    private function mark(ReportSchedule $schedule, string $status, bool $ran): string
    {
        if ($ran || $schedule->last_status !== $status) {
            $schedule->forceFill($ran ? ['last_status' => $status, 'last_run_at' => now()] : ['last_status' => $status])->saveQuietly();
        }

        return $status;
    }
}
