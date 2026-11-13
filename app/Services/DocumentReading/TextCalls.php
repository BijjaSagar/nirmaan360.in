<?php

namespace App\Services\DocumentReading;

use App\Models\AiTextCall;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Agents\CreditService;
use App\Services\Agents\ModelGateway;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\PlanWording;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Where a feature asks for a text call: the free sentences of a letter, a ranking of candidates, a
 * grouping of notes. start() checks everything that could stop the call, holds its maximum in the
 * company's wallet and hands it to the queue. The answer reaches the feature through the handler
 * it registered for the purpose (Contracts\TextCallHandler).
 *
 * The route that calls start() carries RequireAiReader, "can:agents.use" and the "ai-text"
 * throttle. The checks are repeated here, so a call started from a feature's own code is held to
 * them as well.
 */
class TextCalls
{
    /** No key is saved, or the provider is refusing every call for now. The same code the guarded routes answer with. */
    public const NOT_CONNECTED = 'not_connected';

    /** Room for the sentence that tells the model what was wrong with a refused answer. */
    private const CORRECTION_CHARS = 800;

    /** What a text call is assumed to send when a screen says "Uses up to :n credits" before anything is built. */
    private const USUAL_CHARS = 6000;

    public function __construct(
        private TextCallHandlers $handlers,
        private TextCall $call,
        private TextCallRunner $runner,
        private CreditService $credits,
        private AiSpend $spend,
        private ReadQuote $quote,
        private ModelGateway $gateway,
    ) {}

    /**
     * Makes a text call about a record and queues it. The record is what the handler builds the
     * request from and where it keeps the answer; it must be saved and belong to the person's company.
     *
     * A repeated client_uuid returns the call it made the first time. Without a client_uuid, a call
     * for the same purpose and record that is still waiting or running is returned and nothing new
     * is made, so pressing a button twice costs nothing either way. A feature that needs several
     * calls about one record at a time (a long list sent in parts) gives each its own client_uuid.
     *
     * It takes the lock on the company's wallet. Call it before the request writes to the record,
     * or outside that transaction: the job that ends a call holds the wallet while the handler
     * writes to the record, and two transactions that take those two in opposite order would wait
     * on each other.
     *
     * @param  array{project?: ?Project, client_uuid?: ?string}  $with  "project" names the project the record belongs to when
     *                                                                  the record has no project_id of its own; leave it out and
     *                                                                  the record's project_id is used, or the record itself
     *                                                                  when it is a project.
     *
     * @throws AuthorizationException The person may not use the AI features, or the plan has none (403).
     * @throws ReadRefused consent_required or not_connected (409).
     * @throws SpendRefused A platform or company limit, a daily budget or the wallet refused it (409 or 402). Nothing was made.
     * @throws ValidationException A client_uuid that belongs to another call.
     * @throws NothingToSend The handler found nothing to ask.
     */
    public function start(string $purpose, Model $subject, User $by, array $with = []): AiTextCall
    {
        $type = $subject->getMorphClass();

        if (! $subject->exists || strlen($type) > 40) {
            throw new LogicException('A text call is about a saved record with a morph name of at most 40 characters.');
        }

        if ((int) $subject->getAttribute('organization_id') !== (int) $by->organization_id) {
            throw new LogicException('A text call is made by someone of the record\'s own company.');
        }

        $handler = $this->handlers->get($purpose, $type);
        $project = $with['project'] ?? null;

        if ($project !== null && (int) $project->organization_id !== (int) $by->organization_id) {
            throw new LogicException('A text call belongs to a project of the person\'s own company.');
        }

        $organization = Organization::findOrFail($by->organization_id);
        $this->assertOffered($organization, $by, $purpose);

        $call = new AiTextCall([
            'organization_id' => $organization->id,
            // Copied from the record, never left to default: a call without its project would be open to people limited to other projects.
            'project_id' => match (true) {
                array_key_exists('project', $with) => $project?->id,
                $subject instanceof Project => $subject->getKey(),
                default => $subject->getAttribute('project_id'),
            },
            'user_id' => $by->id,
            'purpose' => $purpose,
            'subject_type' => $type,
            'subject_id' => $subject->getKey(),
            'client_uuid' => $with['client_uuid'] ?? null,
            'model' => (string) TextRequest::figures($purpose)['model'],
            'status' => AiTextCall::QUEUED,
        ]);

        // Built once here, so a request that may not be sent is found before anything is held, and to size the call.
        $body = $this->call->body($handler, $call);
        $chars = TextRequest::chars($body);
        $most = $this->quote->worst((string) $body['model'], TextRequest::tokens($chars['all'] + self::CORRECTION_CHARS), (int) $body['max_tokens']);

        return DB::transaction(function () use ($call, $organization, $by, $purpose, $most, $chars) {
            // The wallet's lock makes this one start at a time for a company, so two requests cannot both take the last credits or make the same call twice.
            $this->credits->wallet($organization);

            if (($earlier = $this->earlier($call)) !== null) {
                return $earlier;
            }

            $this->spend->assertRoom($organization, $by, $purpose, $most);

            if (Dec::of($this->credits->free($organization))->isLessThan(Dec::of($most))) {
                throw new SpendRefused(SpendRefused::NO_CREDITS, 402, __('Your company does not have enough AI credits for this.'), ['limit' => InsufficientCredits::WALLET]);
            }

            $call->forceFill(['max_credits' => $most, 'input_chars' => $chars['message']])->save();
            $this->runner->dispatch((int) $call->id);

            return $call->refresh();
        });
    }

    /**
     * The call as it is now, after looking after it: a call whose worker died or that nobody picked
     * up is ended here. A feature's status request calls this each time, so a call someone is
     * waiting for comes to an end even if the scheduler is not running.
     */
    public function watch(AiTextCall $call): AiTextCall
    {
        return $this->runner->tend($call) ?? $call;
    }

    /**
     * For a button's "Uses up to :n credits": the most one call of this purpose can cost, rounded
     * up to a whole credit. A call that was made says it exactly in its max_credits.
     *
     * @param  ?int  $chars  How many characters the request will carry, when the feature knows.
     */
    public function upTo(string $purpose, ?int $chars = null): string
    {
        $figures = TextRequest::figures($purpose);
        $tokens = TextRequest::tokens(min(TextRequest::longest(), max(0, $chars ?? self::USUAL_CHARS) + self::CORRECTION_CHARS));

        return (string) Dec::of($this->quote->worst((string) $figures['model'], $tokens, (int) $figures['max_tokens']))->toScale(0, RoundingMode::Ceiling)->toScale(4);
    }

    /**
     * What the guarded route already checked, for a call that is started from a feature's own code.
     *
     * @throws AuthorizationException
     * @throws ReadRefused
     */
    private function assertOffered(Organization $organization, User $by, string $purpose): void
    {
        if (! $by->is_active || ! $by->hasPermission('agents.use')) {
            throw new AuthorizationException;
        }

        if (! $organization->allowsFeature('ai_agents')) {
            throw new AuthorizationException(PlanWording::moduleNotIncluded());
        }

        if (AiDocumentConsent::covers($purpose) && ! AiDocumentConsent::current($organization)) {
            throw new ReadRefused(ReadDesk::CONSENT_REQUIRED, __('Your company has not agreed to the current notice for sending documents to the AI service. An owner or admin can do that in Settings.'));
        }

        if (! $this->gateway->isConnected() || ProviderBlock::current() !== null) {
            throw new ReadRefused(self::NOT_CONNECTED, __('The AI service is not available right now. Try again later.'));
        }
    }

    /**
     * A call that already stands for this request: the one a repeated client_uuid made, or, for a
     * request without one, a call for the same purpose and record that is still waiting or running.
     *
     * @throws ValidationException When the client_uuid belongs to a call the person cannot reach, or to a call about something else.
     */
    private function earlier(AiTextCall $call): ?AiTextCall
    {
        $same = fn () => AiTextCall::withoutGlobalScopes()
            ->where('organization_id', $call->organization_id)
            ->where('purpose', $call->purpose)
            ->where('subject_type', $call->subject_type)
            ->where('subject_id', $call->subject_id);

        if ($call->client_uuid !== null) {
            $made = ClientUuidRepeat::reachable(AiTextCall::withoutGlobalScopes()->where('organization_id', $call->organization_id)->where('client_uuid', $call->client_uuid)->first());

            if ($made !== null && ! $same()->whereKey($made->id)->exists()) {
                throw ValidationException::withMessages(['client_uuid' => [__('Record not found on the server.')]]);
            }

            // A new client_uuid is a new request, even while another call about the same record is open.
            return $made;
        }

        return $same()->whereIn('status', AiTextCall::OPEN)->orderBy('id')->first();
    }
}
