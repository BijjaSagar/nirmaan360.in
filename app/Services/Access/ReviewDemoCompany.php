<?php

namespace App\Services\Access;

use App\Exceptions\ReviewDemoRefused;
use App\Models\Approval;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\BoqItem;
use App\Models\Dpr;
use App\Models\MeasurementEntry;
use App\Models\Organization;
use App\Models\Party;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Models\Worker;
use App\Services\Agents\AgentRegistry;
use App\Services\ApprovalService;
use App\Services\ContractorPortal\ContractorAccessService;
use App\Services\DprService;
use App\Services\MeasurementService;
use App\Services\Notify\AnnouncementService;
use App\Services\SampleDataService;
use App\Services\Workforce\WorkerCardService;
use App\Support\MailTransports;
use Closure;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The company App Store and Google Play reviewers sign in to: its own company, never the owner's, on the
 * Business plan with no end date, filled with the sample data, with every AI agent off, one announcement,
 * and approvals waiting. Run from the console by `review:demo-company`.
 *
 * The owner's password is 40 random characters that nobody ever sees: it goes straight into the hash and
 * is not kept, printed, logged or returned. The owner sets their own through the reset link sent at the
 * end, and a contractor sets theirs through the usual invitation. Nothing this returns holds a password,
 * a reset token, an invitation link or a session.
 */
class ReviewDemoCompany
{
    public const DEFAULT_COMPANY = 'Demo Builders (Sample)';

    public const DEFAULT_NAME = 'Review Owner';

    public const PLAN = 'business';

    /** Under the company's settings, and as an audit entry: a company without both is never touched by a top-up. */
    public const MARKER = 'review_demo';

    public const CREATED = 'review_demo.created';

    public const TOPPED_UP = 'review_demo.topped_up';

    public const ANNOUNCEMENT_TITLE = 'Welcome to the demo site';

    public const ANNOUNCEMENT_BODY = 'Daily reports are due by 6 pm. Try the sample projects: daily reports, measurements, attendance and approvals.';

    /**
     * How many days back a top-up looks for a day with no daily report. Only a bound on the search: the app
     * pulls a report by when it last changed and keeps every report still in review (SyncService::window),
     * so a new report reaches the phone whatever its date.
     */
    private const FREE_DAY_SEARCH = 30;

    private const DOC_LABELS = ['dpr' => 'daily report', 'measurement' => 'measurement', 'ra_bill' => 'RA bill', 'contractor' => 'contractor payment'];

    private const CHECK_ON_WEBSITE = 'The company is made: check this on the website.';

    public function __construct(
        private SignupService $signup,
        private SampleDataService $sample,
        private ReviewDemoExtras $extras,
        private AgentRegistry $agents,
        private AnnouncementService $announcements,
        private ContractorAccessService $contractors,
        private ApprovalService $approvals,
        private DprService $dprs,
        private MeasurementService $measurements,
        private WorkerCardService $cards,
    ) {}

    /**
     * Makes the company. Refuses before changing anything when an email cannot be used or mail could be kept
     * instead of sent. The company, its plan, the agents and the sample data go in together or not at all. The
     * extra records for the Business screens, the announcement, the contractor invitation and the reset link
     * follow, and then the summary is read back; a step or read that fails is reported, not undone, and the
     * company id and the emails already sent are always returned.
     *
     * @return array{organization: Organization, projects: ?list<string>, waiting: ?array<string, int>, agents_off: ?int,
     *               extras: list<string>, announcement: ?string, emails: list<array{to: string, what: string, sent: bool}>,
     *               card: ?array{worker: string, code: string}, problems: list<string>}
     *
     * @throws ReviewDemoRefused
     */
    public function setUp(string $ownerEmail, ?string $contractorEmail = null, ?string $company = null, ?string $name = null): array
    {
        $ownerEmail = User::normaliseEmail($ownerEmail);
        $contractorEmail = blank($contractorEmail) ? null : User::normaliseEmail((string) $contractorEmail);
        $company = blank($company) ? self::DEFAULT_COMPANY : trim((string) $company);
        $name = blank($name) ? self::DEFAULT_NAME : trim((string) $name);

        $this->assertCanSetUp($ownerEmail, $contractorEmail, $company, $name);

        $owner = $this->createCompany($ownerEmail, $company, $name);
        $organization = $owner->organization;

        return $this->asOwner($owner, function () use ($owner, $organization, $contractorEmail) {
            $problems = [];
            $extras = [];
            $emails = [];

            foreach ($this->extras->steps($organization, $owner) as $step) {
                $added = $this->attempt($step['what'], $step['instead'], fn () => DB::transaction($step['add']), $problems);

                if ($added !== null) {
                    $extras[] = $added;
                }
            }

            $announcement = $this->attempt(
                'posting the announcement',
                'Post one from Announcements on the website once the owner has a password.',
                fn () => $this->postAnnouncement($owner, $organization),
                $problems,
            );

            if ($contractorEmail !== null) {
                $invited = $this->attempt(
                    'inviting the contractor',
                    'Invite them from the sample subcontractor\'s page on the website once the owner has a password.',
                    fn () => $this->inviteContractor($owner, $organization, $contractorEmail),
                    $problems,
                );

                if ($invited !== null) {
                    $emails[] = $invited;
                }
            }

            $reset = $this->attempt(
                'sending the password link',
                'Use "Forgot password?" on the sign-in page with the owner email.',
                fn () => $this->sendPasswordLink($owner),
                $problems,
            );

            if ($reset !== null) {
                $emails[] = $reset;
            }

            // Read back last, each read on its own: the company and the emails above stand whatever happens here.
            return [
                'organization' => $this->attempt('reading the company back', self::CHECK_ON_WEBSITE, fn () => $organization->refresh(), $problems) ?? $organization,
                'projects' => $this->attempt('listing the projects', self::CHECK_ON_WEBSITE, fn () => $this->projectNames($organization), $problems),
                'waiting' => $this->attempt('counting the approvals waiting', self::CHECK_ON_WEBSITE, fn () => $this->waiting($organization, $owner), $problems),
                'agents_off' => $this->attempt('counting the agents switched off', self::CHECK_ON_WEBSITE, fn () => $this->agentsOff($organization), $problems),
                'extras' => $extras,
                'announcement' => $announcement,
                'emails' => $emails,
                'card' => $this->attempt('reading a worker card', 'Print the cards from /workers/cards on the website.', fn () => $this->card($organization), $problems),
                'problems' => $problems,
            ];
        });
    }

    /**
     * Makes sure the first sample project has a daily report waiting at the project manager and a measurement
     * waiting at the QS, adding one of each only when none waits. Today's report is never used.
     *
     * @return array{organization: Organization, project: string, added: list<string>, waiting: ?array<string, int>, today_free: ?bool, problems: list<string>}
     *
     * @throws ReviewDemoRefused
     */
    public function topUp(string $ownerEmail): array
    {
        try {
            $owner = User::findByEmail($ownerEmail);
        } catch (Throwable $exception) {
            throw new ReviewDemoRefused('The owner could not be looked up ('.$this->describe($exception).'). Nothing was changed.');
        }

        if ($owner === null || $owner->role !== 'owner' || $owner->isAnonymised()) {
            throw new ReviewDemoRefused('No owner account uses that email. Nothing was changed.');
        }

        $organization = $owner->organization;

        if ($organization === null || ! $this->madeHere($organization)) {
            throw new ReviewDemoRefused('That company was not made by review:demo-company, so it is left alone. Nothing was changed.');
        }

        $tower = Project::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('code', SampleDataService::TOWER_CODE)
            ->whereNull('deleted_at')
            ->first() ?? throw new ReviewDemoRefused('The first sample project is gone from this company. Nothing was changed.');

        return $this->asOwner($owner, function () use ($owner, $organization, $tower) {
            try {
                $added = DB::transaction(function () use ($owner, $organization, $tower) {
                    $by = $this->fieldUser($organization, $owner);
                    $added = [];

                    if ($this->waitingOn($tower, Dpr::class, 'pm') === 0) {
                        $added[] = $this->addDpr($tower, $by);
                    }

                    if ($this->waitingOn($tower, MeasurementEntry::class, 'qs') === 0) {
                        $added[] = $this->addMeasurement($tower, $by);
                    }

                    AuditLog::record(self::TOPPED_UP, $organization, null, ['added' => count($added)], actor: $owner, organizationId: $organization->id);

                    return $added;
                });
            } catch (Throwable $exception) {
                throw new ReviewDemoRefused('The top-up could not be made ('.$this->describe($exception).'). Nothing was changed.');
            }

            $problems = [];
            $checkAgain = 'The top-up is made: check this on the website.';

            return [
                'organization' => $this->attempt('reading the company back', $checkAgain, fn () => $organization->refresh(), $problems) ?? $organization,
                'project' => $tower->name,
                'added' => $added,
                'waiting' => $this->attempt('counting the approvals waiting', $checkAgain, fn () => $this->waiting($organization, $owner), $problems),
                'today_free' => $this->attempt('checking today\'s daily report', $checkAgain, fn () => ! Dpr::withoutGlobalScopes()->where('project_id', $tower->id)->whereNull('deleted_at')->whereDate('report_date', now()->toDateString())->exists(), $problems),
                'problems' => $problems,
            ];
        });
    }

    /** Both marks this command leaves. People cannot write either one, so a real company never has them. */
    public function madeHere(Organization $organization): bool
    {
        return ($organization->settings[self::MARKER] ?? null) !== null
            && AuditLog::withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('action', self::CREATED)
                ->where('auditable_type', $organization->getMorphClass())
                ->where('auditable_id', $organization->id)
                ->exists();
    }

    /** @throws ReviewDemoRefused */
    private function assertCanSetUp(string $ownerEmail, ?string $contractorEmail, string $company, string $name): void
    {
        $checks = Validator::make(
            ['owner_email' => $ownerEmail, 'contractor' => $contractorEmail, 'company' => $company, 'name' => $name],
            [
                'owner_email' => ['required', 'email', 'max:190'],
                'contractor' => ['nullable', 'email', 'max:190'],
                'company' => ['required', 'string', 'max:120'],
                'name' => ['required', 'string', 'max:120'],
            ],
        );

        if ($checks->fails()) {
            throw new ReviewDemoRefused($checks->errors()->first().' Nothing was changed.');
        }

        if ($problem = $this->emailProblem($ownerEmail)) {
            $hint = User::emailTaken($ownerEmail) ? ' Use --top-up for a demo owner this command made.' : '';

            throw new ReviewDemoRefused("The owner email {$problem}.{$hint} Nothing was changed.");
        }

        if (! is_array(config('buildos.plans.'.self::PLAN))) {
            throw new ReviewDemoRefused('The Business plan is not offered, so the demo company cannot be put on it. Nothing was changed.');
        }

        if ($contractorEmail !== null) {
            if ($contractorEmail === $ownerEmail) {
                throw new ReviewDemoRefused('The contractor email must differ from the owner email: they are two separate logins. Nothing was changed.');
            }

            if ($problem = $this->emailProblem($contractorEmail)) {
                throw new ReviewDemoRefused("The contractor email {$problem}. Nothing was changed.");
            }

            if (! in_array('contractor_portal', config('buildos.plans.'.self::PLAN.'.features', []), true)) {
                throw new ReviewDemoRefused('The Business plan does not include the contractor portal, so no contractor login can be given. Nothing was changed.');
            }
        }

        $this->assertMailOnlySends();
    }

    /**
     * Every mailer the default one can hand mail to must send it. The log transport would write the reset and
     * invitation emails, links and tokens included, into the log, and a failover mailer reaches it when its
     * first mailer fails; the array transport sends nothing at all.
     *
     * @throws ReviewDemoRefused
     */
    private function assertMailOnlySends(): void
    {
        $keeps = MailTransports::firstThatKeepsMail();

        if ($keeps === null) {
            return;
        }

        $default = (string) config('mail.default');
        $why = $keeps['mailer'] === $default
            ? "MAIL_MAILER is {$default}, which uses the {$keeps['transport']} transport"
            : "MAIL_MAILER is {$default}, which can hand mail to the {$keeps['mailer']} mailer ({$keeps['transport']} transport)";

        throw new ReviewDemoRefused("No mail can go out safely ({$why}). The log transport writes the password link into the log and the array transport sends nothing. Set MAIL_MAILER to a mailer that only sends. Nothing was changed.");
    }

    private function emailProblem(string $email): ?string
    {
        return match (true) {
            User::emailReservedForPlatform($email) => 'is kept for platform admins',
            User::emailOnReservedDomain($email) => 'ends in .invalid, which is kept for deleted accounts',
            User::emailTaken($email) => 'already has an account (in any letter case)',
            default => null,
        };
    }

    /** @throws ReviewDemoRefused */
    private function createCompany(string $email, string $company, string $name): User
    {
        try {
            return DB::transaction(function () use ($email, $company, $name) {
                $owner = $this->signup->create([
                    'company' => $company,
                    'name' => $name,
                    'email' => $email,
                    // Never held anywhere else: the hash is all that is kept. The owner sets their own from the emailed link.
                    'password' => Str::password(40),
                    'plan' => self::PLAN,
                    'locale' => 'en',
                ], 'console');

                $organization = $owner->organization;
                $organization->forceFill([
                    'plan' => self::PLAN,
                    'plan_status' => 'active',
                    'trial_ends_at' => null,
                    'paid_until' => null,
                    'settings' => [...($organization->settings ?? []), self::MARKER => ['created_at' => now()->toIso8601String()]],
                ])->save();

                // The plan and the fact only: never the name, email or company.
                AuditLog::record(self::CREATED, $organization, null, [
                    'plan' => self::PLAN,
                    'plan_status' => 'active',
                    'trial_ends_at' => null,
                    'paid_until' => null,
                    'agents' => 'off',
                    'sample_data' => true,
                ], actor: $owner, organizationId: $organization->id);

                $this->asOwner($owner, function () use ($organization, $owner) {
                    // Off before the sample data goes in, so nothing of this company can ever reach the AI provider.
                    $this->switchOffAgents($organization);
                    $this->sample->fillCompany($organization, $owner);
                });

                return $owner;
            });
        } catch (Throwable $exception) {
            throw new ReviewDemoRefused('The company could not be made ('.$this->describe($exception).'). Nothing was changed.');
        }
    }

    /** The website's agent settings, saved as "off" for every agent, with no daily brief. */
    private function switchOffAgents(Organization $organization): void
    {
        foreach ($this->agents->all() as $agent) {
            $this->agents->install($agent, $organization)->forceFill(['enabled' => false, 'daily_brief' => false])->save();
        }
    }

    private function agentsOff(Organization $organization): int
    {
        return $this->agents->all()->filter(fn ($agent) => ! $this->agents->isEnabled($this->agents->install($agent, $organization)))->count();
    }

    /** As the company's own admin would from the Announcements page, sent now to the whole team. */
    private function postAnnouncement(User $owner, Organization $organization): string
    {
        $data = Validator::make([
            'client_uuid' => (string) Str::uuid(),
            'title' => self::ANNOUNCEMENT_TITLE,
            'body' => self::ANNOUNCEMENT_BODY,
            'mentions_money' => 'no',
            'audience' => 'all',
            'schedule' => 'now',
        ], $this->announcements->rules($organization))->validate();

        $announcement = $this->announcements->create($owner, $data, null, $organization);

        return "\"{$announcement->title}\" to the whole team ({$announcement->status})";
    }

    /**
     * As the subcontractor's page on the website does it. The invitation link is dropped here: it reaches
     * the contractor only by email.
     *
     * @return array{to: string, what: string, sent: bool}
     */
    private function inviteContractor(User $owner, Organization $organization, string $email): array
    {
        $party = Party::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('type', 'subcontractor')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first() ?? throw new ReviewDemoRefused('the sample subcontractor is missing');

        $data = Validator::make(['email' => $email], $this->contractors->rules())->validate();
        $issued = $this->contractors->invite($owner, $party, $data);

        return ['to' => $issued['invitation']->email, 'what' => "contractor-portal invitation for {$party->name}", 'sent' => $issued['emailed']];
    }

    /**
     * The same mail as "Forgot password?" on the sign-in page.
     *
     * @return array{to: string, what: string, sent: bool}
     */
    private function sendPasswordLink(User $owner): array
    {
        $status = Password::broker()->sendResetLink(['email' => $owner->email]);

        return ['to' => $owner->email, 'what' => 'link to set a password (the "Forgot password?" email)', 'sent' => $status === Password::RESET_LINK_SENT];
    }

    /** @return list<string> */
    private function projectNames(Organization $organization): array
    {
        return Project::withoutGlobalScopes()->where('organization_id', $organization->id)->whereNull('deleted_at')->orderBy('id')->pluck('name')->all();
    }

    /**
     * What the owner's approvals inbox holds, by kind and step.
     *
     * @return array<string, int>
     */
    private function waiting(Organization $organization, User $owner): array
    {
        return Approval::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', 'IN_REVIEW')
            ->orderBy('id')
            ->get()
            ->filter(fn (Approval $approval) => $this->approvals->canAct($approval, $owner))
            ->countBy(fn (Approval $approval) => (self::DOC_LABELS[$approval->doc_type] ?? $approval->doc_type).' at '.$approval->currentRoleLabel())
            ->all();
    }

    /**
     * One sample worker's card, as /workers/cards prints it and the app's "Scan card" reads it: plain text in
     * the QR. Someone not yet marked today, so a scan marks them present.
     *
     * @return ?array{worker: string, code: string}
     */
    private function card(Organization $organization): ?array
    {
        $workers = Worker::withoutGlobalScopes()->where('organization_id', $organization->id)->where('is_active', true)->whereNull('deleted_at')->orderBy('name');
        $marked = Attendance::withoutGlobalScopes()->where('organization_id', $organization->id)->whereDate('attendance_date', now()->toDateString())->pluck('worker_id');
        $worker = (clone $workers)->whereNotIn('id', $marked->all() ?: [0])->first() ?? $workers->first();

        return $worker === null ? null : ['worker' => $worker->name, 'code' => $this->cards->code($worker)];
    }

    /** The sample's site engineer, who sends the reports in. The owner when there is none. */
    private function fieldUser(Organization $organization, User $owner): User
    {
        return User::where('organization_id', $organization->id)->where('role', 'engineer')->whereNull('anonymised_at')->orderBy('id')->first() ?? $owner;
    }

    /** @param  class-string<Dpr|MeasurementEntry>  $class */
    private function waitingOn(Project $project, string $class, string $role): int
    {
        $documents = $class::withoutGlobalScopes()->where('project_id', $project->id)->select('id');

        // Reports can be deleted, measurements cannot: they are reversed instead.
        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $documents->whereNull('deleted_at');
        }

        return Approval::withoutGlobalScopes()
            ->where('organization_id', $project->organization_id)
            ->where('status', 'IN_REVIEW')
            ->where('approvable_type', (new $class)->getMorphClass())
            ->whereIn('approvable_id', $documents)
            ->get()
            ->filter(fn (Approval $approval) => $approval->currentRole() === $role)
            ->count();
    }

    private function addDpr(Project $project, User $by): string
    {
        $date = $this->freeReportDate($project);
        $item = $this->boqItem($project);

        $data = Validator::make([
            'client_uuid' => (string) Str::uuid(),
            'site_id' => Site::withoutGlobalScopes()->where('project_id', $project->id)->whereNull('deleted_at')->orderBy('id')->value('id'),
            'report_date' => $date,
            'weather' => 'Clear',
            'summary' => 'Level 5 column shuttering fixed and checked for line and level.',
            'manpower' => [['trade' => 'Mason', 'count' => 10], ['trade' => 'Helper', 'count' => 24]],
            'work' => $item === null ? [] : [['boq_item_id' => $item->id, 'qty' => '12']],
        ], $this->dprs->rules($project))->validate();

        $dpr = $this->dprs->create($project, $data, $by);
        $approval = $this->dprs->submit($dpr, $by);

        return "daily report for {$date} on {$project->name}, ".$this->landedAt($approval);
    }

    private function addMeasurement(Project $project, User $by): string
    {
        $item = $this->boqItem($project) ?? throw new ReviewDemoRefused('the first sample project has no BOQ lines to measure');

        $data = Validator::make([
            'client_uuid' => (string) Str::uuid(),
            'boq_item_id' => $item->id,
            'measured_on' => now()->subDay()->toDateString(),
            'location' => 'Level 5, panel S1',
            'nos' => '1',
            'length' => '6',
            'breadth' => '5',
            'depth' => '0.5',
        ], $this->measurements->rules($project))->validate();

        $entry = $this->measurements->create($project, $data, $by);
        $approval = $this->measurements->submit($entry, $by);

        return "measurement of BOQ {$item->code} ({$entry->refresh()->qty} {$item->unit}) on {$project->name}, ".$this->landedAt($approval);
    }

    private function landedAt(?Approval $approval): string
    {
        return $approval === null
            ? 'approved at once because the company has no approval steps for it'
            : 'waiting at '.$approval->currentRoleLabel();
    }

    /** The slab line the sample measures, or the first line there is. */
    private function boqItem(Project $project): ?BoqItem
    {
        $items = BoqItem::withoutGlobalScopes()->where('project_id', $project->id)->whereNull('deleted_at');

        return (clone $items)->where('code', '2.1')->first() ?? $items->orderBy('sort')->orderBy('id')->first();
    }

    /** The latest day before today with no report, so today's stays free for the reviewer. Yesterday when every day is taken. */
    private function freeReportDate(Project $project): string
    {
        $taken = Dpr::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->whereDate('report_date', '>=', now()->subDays(self::FREE_DAY_SEARCH)->toDateString())
            ->pluck('report_date')
            ->map(fn ($date) => substr((string) $date, 0, 10))
            ->all();

        for ($daysAgo = 1; $daysAgo <= self::FREE_DAY_SEARCH; $daysAgo++) {
            $date = now()->subDays($daysAgo)->toDateString();

            if (! in_array($date, $taken, true)) {
                return $date;
            }
        }

        return now()->subDay()->toDateString();
    }

    /**
     * Runs as the owner, as the website would, and puts back whoever was signed in before.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function asOwner(User $owner, Closure $work): mixed
    {
        $previous = Auth::user();
        Auth::setUser($owner);

        try {
            return $work();
        } finally {
            $previous === null ? Auth::forgetUser() : Auth::setUser($previous);
        }
    }

    /**
     * A step or a read after the company exists. A failure is noted with what to do instead, and the rest carries on.
     *
     * @template T
     *
     * @param  Closure(): T  $step
     * @param  list<string>  $problems
     * @return ?T
     */
    private function attempt(string $what, string $instead, Closure $step, array &$problems): mixed
    {
        try {
            return $step();
        } catch (Throwable $exception) {
            $problems[] = "Failed while {$what} ({$this->describe($exception)}). {$instead}";

            return null;
        }
    }

    /**
     * Words safe to print and log. Only messages written for people are shown. A database error carries its
     * bindings, which can hold a password hash, so it is named by its SQL state alone, and anything else by its kind.
     */
    public function describe(Throwable $exception): string
    {
        $text = match (true) {
            $exception instanceof ReviewDemoRefused, $exception instanceof HttpExceptionInterface => $exception->getMessage(),
            // What SampleDataService throws when a plan has no room, worded for the owner.
            $exception::class === RuntimeException::class => $exception->getMessage(),
            $exception instanceof ValidationException => (string) collect($exception->errors())->flatten()->first(),
            $exception instanceof QueryException => 'database error, SQLSTATE '.$exception->getCode(),
            default => class_basename($exception),
        };

        Log::warning('review:demo-company step failed', ['error' => class_basename($exception), 'detail' => $text]);

        return $text;
    }
}
