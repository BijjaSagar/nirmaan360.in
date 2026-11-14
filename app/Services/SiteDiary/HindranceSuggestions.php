<?php

namespace App\Services\SiteDiary;

use App\Models\AiTextCall;
use App\Models\AuditLog;
use App\Models\HindranceSuggestion;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\NothingToSend;
use App\Services\DocumentReading\ReadRefused;
use App\Services\DocumentReading\SpendRefused;
use App\Services\DocumentReading\TextCalls;
use App\Services\DocumentReading\TextRequest;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Idempotent;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Suggest groups" on the hindrance register: the AI is sent the hindrance notes of daily reports that
 * no entry cites yet, and what it groups comes back as entries waiting to be confirmed. The notes are
 * words site staff wrote, so this is sent only under the company's consent switch.
 */
class HindranceSuggestions
{
    public function __construct(
        private HindranceNotes $notes,
        private HindranceGrouping $grouping,
        private TextCalls $calls,
        private AiSpend $spend,
    ) {}

    public function rules(): array
    {
        return [
            // Sent by the form, so pressing the button twice asks once.
            'client_uuid' => ['required', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * Pins the reports to send and asks the AI to group them. A repeated client_uuid returns the
     * original request and asks nothing.
     *
     * @param  array<string, mixed>  $data  Validated with rules().
     *
     * @throws ValidationException Nothing to send, or a request of this project is still waiting.
     * @throws SpendRefused|ReadRefused|AuthorizationException As TextCalls::start. Nothing is kept then.
     */
    public function start(Project $project, array $data, User $by): HindranceSuggestion
    {
        $uuid = (string) $data['client_uuid'];
        $find = fn () => ClientUuidRepeat::reachable(HindranceSuggestion::withoutGlobalScopes()->where('organization_id', $project->organization_id)->where('client_uuid', $uuid)->first());

        if ($existing = $find()) {
            return $this->sameProject($existing, $project);
        }

        if ($this->latest($project)?->isWaiting()) {
            throw ValidationException::withMessages(['ai_reader' => __('The AI is still grouping notes on this project. Wait for it to finish.')]);
        }

        $from = $data['from'] ?? null;
        $to = $data['to'] ?? null;
        $ids = $this->notes->pick($project, $from, $to);

        if ($ids === []) {
            throw ValidationException::withMessages(['ai_reader' => __('No daily report in these dates has a hindrance note that the register does not already cite.')]);
        }

        $suggestion = Idempotent::create(fn () => HindranceSuggestion::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'user_id' => $by->id,
            'dpr_ids' => $ids,
            'from_date' => $from,
            'to_date' => $to,
            'status' => HindranceSuggestion::WAITING,
            'client_uuid' => $uuid,
        ]), $find);

        if (! $suggestion->wasRecentlyCreated) {
            return $this->sameProject($suggestion, $project);
        }

        // Outside any transaction of ours: making the call takes the company's wallet, and the job that ends it takes the wallet before the register.
        try {
            $this->calls->start(HindranceGrouping::PURPOSE, $suggestion, $by);
        } catch (NothingToSend) {
            $suggestion->delete();

            throw ValidationException::withMessages(['ai_reader' => __('No daily report in these dates has a hindrance note that the register does not already cite.')]);
        } catch (Throwable $refused) {
            // A limit, the wallet, the switch or the plan said no. Nothing was sent or held, so nothing is kept.
            $suggestion->delete();

            throw $refused;
        }

        AuditLog::record('hindrance.grouping_asked', $suggestion, null, [
            'reports' => count($ids),
            'from' => $from,
            'to' => $to,
        ], $by);

        return $suggestion;
    }

    /**
     * The latest request of the project, after looking after its call: a call whose worker died, or
     * that nobody picked up, is ended here, so a request someone is waiting for comes to an end.
     */
    public function latest(Project $project): ?HindranceSuggestion
    {
        $suggestion = HindranceSuggestion::query()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->latest('id')
            ->first();

        if ($suggestion === null || ! $suggestion->isWaiting()) {
            return $suggestion;
        }

        $call = $this->callOf($suggestion);

        if ($call !== null && $call->isOpen()) {
            $this->calls->watch($call);
        } elseif ($call === null && $suggestion->created_at?->lt(now()->subMinute())) {
            // The call was never made and nothing will end the request otherwise.
            $this->grouping->failed(new AiTextCall(['organization_id' => $suggestion->organization_id, 'subject_id' => $suggestion->id]), 'not_started');
        }

        return $suggestion->refresh();
    }

    /**
     * What the register shows about grouping for this person.
     *
     * offered: the button may be shown: the person may make entries and use the AI, and the readers are on for the company.
     * consent: the company has agreed to the current notice; without it nothing can be sent.
     * reports: how many reports a request would send now. credits: the most it can cost, in whole credits.
     * latest: the latest request, with why it ended when it failed.
     *
     * @return array{offered: bool, consent: bool, reports: int, credits: ?string, latest: ?HindranceSuggestion, reason: ?string}
     */
    public function panel(Project $project, User $user): array
    {
        $organization = $user->organization;
        $offered = $user->can('site_diary.manage') && $user->can('dpr.view') && $user->can('agents.use') && $this->spend->readersOn($organization);
        $latest = $offered || HindranceSuggestion::query()->where('project_id', $project->id)->exists() ? $this->latest($project) : null;
        $consent = $offered && AiDocumentConsent::current($organization);
        $ids = $offered ? $this->notes->pick($project) : [];

        return [
            'offered' => $offered,
            'consent' => $consent,
            'reports' => count($ids),
            'credits' => $ids === [] ? null : $this->upTo($project, $ids),
            'latest' => $latest,
            'reason' => $latest?->status === HindranceSuggestion::FAILED ? self::failure((string) $latest->fail_reason) : null,
        ];
    }

    /** Why a request ended without entries, in the reader's language. Nothing was charged for it. */
    public static function failure(string $reason): string
    {
        return match ($reason) {
            'nothing_to_group', 'subject_gone' => __('The daily reports changed before the AI could read them, so nothing was sent.'),
            'consent_off' => __('The AI document readers were switched off for your company, so nothing was sent.'),
            'not_started' => __('The server did not get to it in time, so nothing was sent. Ask again later.'),
            default => __('The AI could not group the notes this time. You were not charged.'),
        };
    }

    /** The most a request for these reports can cost, from the request as it would be sent. */
    private function upTo(Project $project, array $ids): string
    {
        $call = new AiTextCall(['organization_id' => $project->organization_id, 'purpose' => HindranceGrouping::PURPOSE]);
        $suggestion = new HindranceSuggestion(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'dpr_ids' => $ids]);

        try {
            $most = $this->calls->upTo(HindranceGrouping::PURPOSE, TextRequest::chars($this->grouping->body($call, $suggestion))['all']);
        } catch (NothingToSend) {
            $most = $this->calls->upTo(HindranceGrouping::PURPOSE);
        }

        return (string) Dec::of($most)->toScale(0, RoundingMode::Ceiling);
    }

    private function callOf(HindranceSuggestion $suggestion): ?AiTextCall
    {
        return AiTextCall::withoutGlobalScopes()
            ->where('organization_id', $suggestion->organization_id)
            ->where('purpose', HindranceGrouping::PURPOSE)
            ->where('subject_type', $suggestion->getMorphClass())
            ->where('subject_id', $suggestion->id)
            ->latest('id')
            ->first();
    }

    private function sameProject(HindranceSuggestion $suggestion, Project $project): HindranceSuggestion
    {
        if ((int) $suggestion->project_id !== (int) $project->id) {
            throw ValidationException::withMessages(['client_uuid' => __('This request id was already used on another project.')]);
        }

        return $suggestion;
    }
}
