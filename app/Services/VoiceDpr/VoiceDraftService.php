<?php

namespace App\Services\VoiceDpr;

use App\Models\AiTextCall;
use App\Models\AuditLog;
use App\Models\DprVoiceDraft;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentReading\ReaderSettings;
use App\Services\DocumentReading\ReadRefused;
use App\Services\DocumentReading\SpendRefused;
use App\Services\DocumentReading\TextCalls;
use App\Support\ClientTime;
use App\Support\ClientUuidRepeat;
use App\Support\Idempotent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Spoken daily reports from the field app: taking the words, asking for the proposal, answering the
 * phone while it waits, and recording which DPR the person made from it.
 *
 * Nothing here writes a DPR, a DPR row or anything a DPR counts. The phone fills its own form from
 * the proposal after the person has checked it, and sends the DPR through its outbox as always.
 */
class VoiceDraftService
{
    /** A draft whose call was never made is given up after this many minutes. */
    private const NOT_STARTED_MINUTES = 15;

    public function __construct(private TextCalls $calls, private VoiceContext $context, private ReaderSettings $settings) {}

    /**
     * Whether the request must be refused because the words were not heard on the phone itself: no
     * speech block, or an engine other than the app's two own recognisers, while the server takes
     * words from those alone.
     */
    public function notOnDevice(mixed $speech): bool
    {
        if (! config('modules.voice_dpr.on_device_only')) {
            return false;
        }

        return ! is_array($speech) || ! in_array($speech['engine'] ?? null, (array) config('modules.voice_dpr.engines'), true);
    }

    /** @return array<string, mixed> */
    public function rules(Project $project): array
    {
        $onDevice = (bool) config('modules.voice_dpr.on_device_only');

        return [
            'client_uuid' => ['required', 'uuid'],
            'transcript' => ['required', 'string', 'max:'.(int) config('modules.voice_dpr.transcript_max'), 'regex:/[\p{L}\p{N}]/u'],
            'locale' => ['required', 'string', Rule::in(array_keys((array) config('modules.voice_dpr.locales')))],
            'report_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'site_id' => ['bail', 'nullable', 'integer', Rule::exists('sites', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'recorded_at' => ['nullable', 'date'],
            'speech' => [$onDevice ? 'required' : 'nullable', 'array'],
            'speech.engine' => array_values(array_filter(['required_with:speech', 'string', 'max:40', $onDevice ? Rule::in((array) config('modules.voice_dpr.engines')) : null])),
            'speech.os' => ['nullable', 'string', 'max:60'],
            'speech.offline_checked' => ['nullable', 'boolean'],
            'platform' => ['required', 'string', Rule::in(['android', 'ios'])],
            'app_version' => ['required', 'string', 'max:20'],
        ];
    }

    /** @return array<string, mixed> */
    public function linkRules(): array
    {
        return [
            'dpr_client_uuid' => ['required', 'uuid'],
            'changed' => ['present', 'array', 'max:400'],
            'changed.*' => ['string', 'max:40', 'regex:/^[a-z_]{1,20}(\.\d{1,3}(\.[a-z_]{1,20})?)?$/'],
        ];
    }

    /**
     * Takes the words and asks for the proposal. A repeated client_uuid by the same person returns the
     * draft it made the first time, whatever has happened to it since.
     *
     * @param  array<string, mixed>  $data  Validated against rules().
     *
     * @throws ReadRefused Voice is not switched on, the company has not agreed to the notice, or the AI service is not available.
     * @throws SpendRefused A limit, a budget or the wallet refused it. Nothing was kept.
     * @throws ValidationException The client_uuid belongs to someone else's request.
     */
    public function create(Project $project, array $data, User $by): DprVoiceDraft
    {
        if (! $this->settings->voiceOffered()) {
            throw new ReadRefused(SpendRefused::NOT_RELEASED, __('Voice reports are not switched on yet.'));
        }

        $uuid = (string) $data['client_uuid'];
        $find = fn () => DprVoiceDraft::withoutGlobalScopes()->where('organization_id', $project->organization_id)->where('client_uuid', $uuid)->first();

        if (($earlier = $find()) !== null) {
            return $this->repeated($earlier, $by);
        }

        $speech = is_array($data['speech'] ?? null) ? Arr::only($data['speech'], ['engine', 'os', 'offline_checked']) : null;

        $draft = Idempotent::create(fn () => DprVoiceDraft::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'site_id' => $data['site_id'] ?? null,
            'user_id' => $by->id,
            'client_uuid' => $uuid,
            'report_date' => $data['report_date'],
            'locale' => $data['locale'],
            'transcript' => trim((string) $data['transcript']),
            'speech' => $speech,
            'platform' => $data['platform'],
            'app_version' => $data['app_version'],
            'recorded_at' => ClientTime::parse($data['recorded_at'] ?? null),
            'status' => DprVoiceDraft::QUEUED,
            'checks' => ['refs' => $this->context->refsFor($project, (string) $data['transcript'])],
        ]), $find);

        if (! $draft->wasRecentlyCreated) {
            return $this->repeated($draft, $by);
        }

        try {
            // Outside any transaction of ours: the call takes the lock on the company's wallet.
            $call = $this->calls->start(VoiceDraftCall::PURPOSE, $draft, $by);
        } catch (Throwable $refused) {
            // Nothing was sent or held. The words are not kept, so the same request can be sent again.
            DprVoiceDraft::withoutGlobalScopes()->whereKey($draft->id)->delete();

            throw $refused;
        }

        DprVoiceDraft::withoutGlobalScopes()->whereKey($draft->id)->update(['ai_text_call_id' => $call->id]);

        AuditLog::record('voice_dpr.draft_created', $draft, null, [
            'locale' => $draft->locale,
            'engine' => $speech['engine'] ?? null,
            'platform' => $draft->platform,
            'chars' => mb_strlen((string) $draft->transcript),
        ], $by);

        $fresh = DprVoiceDraft::withoutGlobalScopes()->findOrFail($draft->id);
        $fresh->wasRecentlyCreated = true;

        return $fresh;
    }

    /**
     * The draft as it is now, after looking after its call: a call nobody picked up, or whose worker
     * died, is ended here, so a phone that waits always sees an end even without the scheduler.
     */
    public function watch(DprVoiceDraft $draft): DprVoiceDraft
    {
        if ($draft->status !== DprVoiceDraft::QUEUED) {
            return $draft;
        }

        $call = $draft->ai_text_call_id === null ? null : AiTextCall::withoutGlobalScopes()
            ->where('organization_id', $draft->organization_id)
            ->find($draft->ai_text_call_id);

        if ($call !== null) {
            $call = $this->calls->watch($call);
            $draft->refresh();
        }

        // The call ended without moving the draft on, or was never made.
        $reason = match (true) {
            $draft->status !== DprVoiceDraft::QUEUED => null,
            $call !== null && ! $call->isOpen() => (string) ($call->fail_reason ?: 'internal_error'),
            $call === null && $draft->created_at?->lt(now()->subMinutes(self::NOT_STARTED_MINUTES)) => 'not_started',
            default => null,
        };

        if ($reason !== null) {
            DprVoiceDraft::withoutGlobalScopes()->whereKey($draft->id)->where('status', DprVoiceDraft::QUEUED)
                ->update(['status' => DprVoiceDraft::FAILED, 'fail_reason' => substr($reason, 0, 24)]);
            $draft->refresh();
        }

        return $draft;
    }

    /**
     * Records the DPR the person made from the proposal, by the client_uuid the phone sent it with,
     * and the fields they changed by hand. The words are then kept for the longer period.
     *
     * @param  array<string, mixed>  $data  Validated against linkRules().
     *
     * @throws ReadRefused The draft has no proposal, or is linked to another DPR already.
     */
    public function link(DprVoiceDraft $draft, User $by, array $data): DprVoiceDraft
    {
        return DB::transaction(function () use ($draft, $by, $data) {
            $locked = DprVoiceDraft::withoutGlobalScopes()->where('organization_id', $draft->organization_id)->lockForUpdate()->findOrFail($draft->id);

            if ($locked->status !== DprVoiceDraft::READY) {
                throw new ReadRefused(ReadRefused::WRONG_STATE, __('This voice draft cannot be linked as it is now.'), ['status' => $locked->status]);
            }

            if ($locked->dpr_client_uuid !== null) {
                if ($locked->dpr_client_uuid === $data['dpr_client_uuid']) {
                    return $locked;
                }

                throw new ReadRefused('already_linked', __('This voice draft is already linked to another daily report.'));
            }

            $changed = array_values(array_unique(array_map('strval', (array) $data['changed'])));
            $locked->forceFill(['dpr_client_uuid' => $data['dpr_client_uuid'], 'changed' => $changed])->save();

            AuditLog::record('voice_dpr.draft_linked', $locked, null, ['changed' => count($changed)], $by);

            return $locked;
        });
    }

    /** Only the person who spoke a draft reads or links it. Anyone else is told it does not exist. */
    public function assertOwn(DprVoiceDraft $draft, User $user): void
    {
        abort_unless((int) $draft->user_id === (int) $user->id, 404);
    }

    /**
     * What the phone reads.
     *
     * @return array<string, mixed>
     */
    public function present(DprVoiceDraft $draft): array
    {
        $call = $draft->ai_text_call_id === null ? null : AiTextCall::withoutGlobalScopes()
            ->where('organization_id', $draft->organization_id)
            ->find($draft->ai_text_call_id);
        $failed = $draft->status === DprVoiceDraft::FAILED;

        return [
            'id' => (int) $draft->id,
            'status' => $draft->status,
            'client_uuid' => $draft->client_uuid,
            'project_id' => (int) $draft->project_id,
            'site_id' => $draft->site_id === null ? null : (int) $draft->site_id,
            'report_date' => $draft->report_date?->toDateString(),
            'locale' => $draft->locale,
            'transcript' => $draft->transcript,
            'proposal' => $draft->status === DprVoiceDraft::READY ? $draft->proposal : null,
            'credits' => (string) ($call?->credits ?? '0.0000'),
            'fail_reason' => $failed ? $draft->fail_reason : null,
            'message' => $failed ? __('No draft could be made from these words. Type the report, or use the words as notes.') : null,
            'linked' => $draft->dpr_client_uuid !== null,
            'text_purged' => $draft->text_purged_at !== null,
        ];
    }

    /** The draft a repeated request made, for its own person only. */
    private function repeated(DprVoiceDraft $earlier, User $by): DprVoiceDraft
    {
        $earlier = ClientUuidRepeat::reachable($earlier);

        if ((int) $earlier->user_id !== (int) $by->id) {
            throw ValidationException::withMessages(['client_uuid' => [__('Record not found on the server.')]]);
        }

        return $earlier;
    }
}
