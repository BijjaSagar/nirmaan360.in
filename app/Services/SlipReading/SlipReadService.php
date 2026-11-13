<?php

namespace App\Services\SlipReading;

use App\Models\AuditLog;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SlipRead;
use App\Models\User;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\ReadAccess;
use App\Services\DocumentReading\ReadDesk;
use App\Services\DocumentReading\ReadIntake;
use App\Services\DocumentReading\ReadKinds;
use App\Services\DocumentReading\ReadLifecycle;
use App\Services\DocumentReading\ReadQuote;
use App\Services\DocumentReading\ReadRefused;
use App\Services\DocumentReading\ReadTending;
use App\Services\DocumentReading\ReadWords;
use App\Services\DocumentReading\SpendRefused;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Idempotent;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reading a photo to fill a form: a delivery challan or weighbridge slip on the challan form, and,
 * once its reader is deployed, a supplier bill on a bill form. The reading happens before the form
 * is saved and only fills the form; saving goes through the form's own service, which checks every
 * filled field through SlipLink.
 *
 * A reading is one person's own: only they, an owner or an admin may open it, and only they may
 * save a form with it.
 */
class SlipReadService
{
    public function __construct(
        private ReadDesk $desk,
        private ReadIntake $intake,
        private ReadKinds $kinds,
        private ReadQuote $quotes,
        private ReadTending $tending,
        private ReadLifecycle $lifecycle,
        private AiSpend $spend,
    ) {}

    /** Whether this kind of slip can be read for this company now: its reader is deployed and the readers are on. */
    public function offered(Organization $organization, string $kind): bool
    {
        return isset(SlipRead::READ_KINDS[$kind]) && $this->spend->readersOn($organization, SlipRead::READ_KINDS[$kind]);
    }

    /** The most one reading of this kind can cost, for the button: one call with the largest picture, writing its most. */
    public function upTo(string $kind, bool $stronger = false): string
    {
        $readKind = SlipRead::READ_KINDS[$kind];
        $figures = config("modules.document_reading.kinds.{$readKind}.passes.main");

        return $this->quotes->upTo($readKind, 'main', $stronger ? ($figures['stronger'] ?? null) : null);
    }

    /** The same in whole credits, for the words on a button. */
    public function upToWhole(string $kind, bool $stronger = false): string
    {
        return (string) Dec::of($this->upTo($kind, $stronger))->toScale(0, RoundingMode::Up);
    }

    /**
     * Stores the photo, holds the credits and hands the photo to the reader at once. A repeated
     * client_uuid gives back the reading it made the first time, to the same person only.
     *
     * @param  string  $bytes  The photo as it arrived. The server redraws it before anything is kept.
     *
     * @throws SpendRefused not_released when this kind's reader is not on, or a limit refused the hold.
     * @throws ReadRefused
     * @throws ValidationException A client_uuid that belongs to something else.
     */
    public function create(Project $project, User $by, string $kind, string $bytes, string $clientUuid, string $channel = 'web'): SlipRead
    {
        if (($earlier = $this->earlier($by, $clientUuid)) !== null) {
            return $earlier;
        }

        $organization = Organization::findOrFail($project->organization_id);

        if (! $this->offered($organization, $kind)) {
            throw new SpendRefused(SpendRefused::NOT_RELEASED, 409, __('The readers are not switched on yet.'));
        }

        $readKind = SlipRead::READ_KINDS[$kind];
        $reader = $this->kinds->get($readKind);
        $read = $this->desk->open($by, $readKind, ['project' => $project, 'client_uuid' => $clientUuid, 'pages_total' => 1, 'channel' => $channel]);
        $page = $this->intake->storePage($read, $reader, 1, ReadIntake::FULL, $bytes);

        $slip = Idempotent::create(fn () => SlipRead::withoutGlobalScopes()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'document_read_id' => $read->id,
            'kind' => $kind,
            'status' => SlipRead::READING,
            'image_sha256' => $page->image_sha256,
            'user_id' => $by->id,
            'client_uuid' => $clientUuid,
        ]), fn () => $this->earlier($by, $clientUuid));

        if (! $slip->wasRecentlyCreated) {
            return $slip;
        }

        try {
            $this->desk->start($read, $by);
        } catch (ReadRefused|SpendRefused $refused) {
            // Refused before anything was held or sent. The draft read keeps its photo, so the same request sent again starts it.
            $slip->delete();

            throw $refused;
        }

        return $slip->refresh();
    }

    /**
     * What the form is offered, for the screen and the phone. A challan reading carries no money
     * figure: its reader has no field for one.
     *
     * @return array<string, mixed>
     */
    public function present(SlipRead $slip, User $viewer): array
    {
        $slip = $this->sync($slip);
        $read = $this->readOf($slip);
        $page = $read === null ? null : $this->pageOf($read);
        $canRecheck = $this->canRecheck($slip, $read, $page);

        return [
            'id' => $slip->id,
            'status' => $slip->status,
            'kind' => $slip->kind,
            'doc_kind' => $slip->doc_kind,
            'fields' => $this->shown($slip->fields ?? []),
            'lines' => array_map(fn (array $line) => $this->shown($line), $slip->lines ?? []),
            'suggestions' => $slip->suggestions ?? ['party' => null, 'materials' => []],
            'warnings' => array_values($slip->warnings ?? []),
            'can_recheck' => $canRecheck,
            'recheck_credits' => $canRecheck ? $this->upToWhole($slip->kind, stronger: true) : null,
            'rechecked' => $slip->rechecked_at !== null,
            'credits' => $read === null ? '0.0000' : (string) $read->credits_used,
            'credits_cap' => $read === null ? '0.0000' : (string) $read->credits_cap,
            'read' => [
                'id' => $read?->id,
                'status' => $read?->status,
                'pause_reason' => $read?->pause_reason,
                'message' => $this->message($slip, $read),
            ],
        ];
    }

    /**
     * Has the stronger model read the photo again. Offered only after an arithmetic check failed
     * (a challan's weights; a bill's lines, taxable value or grand total), never for a GSTIN warning,
     * never twice, never to a company on a trial, and only for a kind whose figures name a stronger model.
     *
     * @throws ReadRefused not_offered, or a refusal of the read's own retry.
     * @throws SpendRefused
     */
    public function recheck(SlipRead $slip, User $by): SlipRead
    {
        $slip = $this->sync($slip);
        $read = $this->readOf($slip);
        $page = $read === null ? null : $this->pageOf($read);

        if (! $this->canRecheck($slip, $read, $page)) {
            throw new ReadRefused(ReadRefused::NOT_OFFERED, __('Reading this photo again is offered only when its figures do not add up.'));
        }

        DB::transaction(function () use ($slip, $read, $page, $by) {
            // The read first, then the slip, as SlipLink does when a form is saved with this reading.
            DocumentRead::withoutGlobalScopes()->where('organization_id', $slip->organization_id)->lockForUpdate()->findOrFail($read->id);
            $locked = SlipRead::withoutGlobalScopes()->whereKey($slip->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SlipRead::READY || $locked->rechecked_at !== null) {
                throw ReadRefused::wrongState($read);
            }

            $locked->forceFill(['rechecked_at' => now(), 'status' => SlipRead::READING])->save();

            // A limit of its own for the one call, so what is left of the first limit does not stop it.
            $this->desk->retry($read, $page, $by, stronger: true, cap: $this->upTo($slip->kind, stronger: true));
        });

        return $slip->refresh();
    }

    /**
     * The person does not want to use this reading. A reading still under way is cancelled; a
     * finished one is closed as discarded. A reading a saved form already used cannot be discarded.
     *
     * @throws ReadRefused wrong_state for a reading already used.
     */
    public function discard(SlipRead $slip, User $by): SlipRead
    {
        if ($slip->status === SlipRead::USED) {
            throw new ReadRefused(ReadRefused::WRONG_STATE, __('This reading was used for a saved record and cannot be discarded.'), ['status' => $slip->status]);
        }

        $read = $this->readOf($slip);

        // A reading still under way gives back its hold and sends nothing more.
        if ($read !== null && in_array($read->status, DocumentRead::OPEN, true)) {
            $this->lifecycle->cancel($read, $by);
        }

        DB::transaction(function () use ($slip, $read, $by) {
            // The read first, then the slip: the order the page reader and SlipLink use.
            $lockedRead = $read === null ? null : DocumentRead::withoutGlobalScopes()->where('organization_id', $slip->organization_id)->lockForUpdate()->find($read->id);
            $locked = SlipRead::withoutGlobalScopes()->whereKey($slip->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === SlipRead::USED) {
                throw new ReadRefused(ReadRefused::WRONG_STATE, __('This reading was used for a saved record and cannot be discarded.'), ['status' => $locked->status]);
            }

            $locked->forceFill(['status' => SlipRead::DISCARDED])->save();

            if ($lockedRead !== null && in_array($lockedRead->status, [DocumentRead::READY, DocumentRead::FAILED], true)) {
                $before = $lockedRead->status;
                $lockedRead->forceFill(['status' => DocumentRead::DISCARDED])->save();
                AuditLog::record('ai.read_discarded', $lockedRead, ['status' => $before], ['status' => DocumentRead::DISCARDED, 'kind' => $lockedRead->kind], $by, (int) $lockedRead->organization_id);
            }
        });

        return $slip->refresh();
    }

    /** Whether this person may open this reading: the creator, an owner or an admin, holding what its kind asks for. */
    public function mayOpen(SlipRead $slip, User $user): bool
    {
        if ((int) $user->organization_id !== (int) $slip->organization_id) {
            return false;
        }

        $own = (int) $slip->user_id === (int) $user->id || ReadAccess::leads($user);

        return $own && $this->mayUseKind($user, $slip->kind);
    }

    /** What a person needs to read this kind of slip. A bill is money, so it needs money.view as well. */
    public function mayUseKind(User $user, string $kind): bool
    {
        return match ($kind) {
            SlipRead::CHALLAN => $user->hasPermission('material_control.challan'),
            SlipRead::BILL => $user->hasPermission('money.view') && ($user->hasPermission('material_control.bills') || $user->hasPermission('finance.manage')),
            default => false,
        };
    }

    /** The reading a repeated client_uuid made, when it is this person's. */
    private function earlier(User $by, string $clientUuid): ?SlipRead
    {
        $earlier = ClientUuidRepeat::reachable(SlipRead::withoutGlobalScopes()->where('organization_id', $by->organization_id)->where('client_uuid', $clientUuid)->first());

        if ($earlier !== null && (int) $earlier->user_id !== (int) $by->id) {
            throw ValidationException::withMessages(['client_uuid' => [__('Record not found on the server.')]]);
        }

        return $earlier;
    }

    /**
     * Moves a watched reading along, as every status request does, and follows its read: a read that
     * was cancelled, expired or ended without an answer leaves nothing to fill the form with.
     */
    private function sync(SlipRead $slip): SlipRead
    {
        $read = $this->readOf($slip);

        if ($read !== null && in_array($read->status, DocumentRead::OPEN, true)) {
            try {
                $this->tending->one($read);
            } catch (Throwable $exception) {
                // Tending is a help, not a condition: the person still gets the answer.
                report($exception);
            }

            $read->refresh();
        }

        $over = $read === null || in_array($read->status, [DocumentRead::CANCELLED, DocumentRead::EXPIRED, DocumentRead::FAILED], true);

        if ($over && in_array($slip->status, [SlipRead::READING, SlipRead::READY], true)) {
            SlipRead::withoutGlobalScopes()->whereKey($slip->id)->whereIn('status', [SlipRead::READING, SlipRead::READY])->update(['status' => SlipRead::FAILED, 'updated_at' => now()]);
        }

        return SlipRead::withoutGlobalScopes()->findOrFail($slip->id);
    }

    private function canRecheck(SlipRead $slip, ?DocumentRead $read, ?DocumentReadPage $page): bool
    {
        if ($slip->status !== SlipRead::READY || $slip->rechecked_at !== null || $read === null || $page === null || $read->files_purged_at !== null) {
            return false;
        }

        $codes = $slip->kind === SlipRead::BILL ? BillChecks::ARITHMETIC : ChallanChecks::ARITHMETIC;
        $arithmetic = collect($slip->warnings ?? [])->contains(fn (array $warning) => in_array($warning['code'] ?? null, $codes, true));

        return $arithmetic && $this->desk->strongerOffered(Organization::findOrFail($slip->organization_id), $read, $page);
    }

    /**
     * A sentence for a reading that has stopped, in the person's language. "Not charged" is said only
     * when nothing was: a second reading that failed after a first one was charged says so instead.
     */
    private function message(SlipRead $slip, ?DocumentRead $read): ?string
    {
        $failed = $read?->status === DocumentRead::FAILED || ($slip->status === SlipRead::FAILED && ! in_array($read?->status, [DocumentRead::CANCELLED, DocumentRead::EXPIRED], true));

        return match (true) {
            $read?->status === DocumentRead::PAUSED => ReadWords::pause($read->pause_reason),
            $failed && $read !== null && Dec::of($read->credits_used)->isPositive() => __('The second reading could not be used. Type the details yourself.'),
            $failed => __('The photo could not be read. You were not charged for it. Type the details yourself.'),
            in_array($read?->status, [DocumentRead::CANCELLED, DocumentRead::EXPIRED], true) => __('This reading has ended. Read the photo again or type the details yourself.'),
            default => null,
        };
    }

    /**
     * A cell as the screen and the phone see it: what the code parsed it as stays on the server.
     *
     * @param  array<string, mixed>  $cells
     * @return array<string, mixed>
     */
    private function shown(array $cells): array
    {
        return array_map(fn ($cell) => is_array($cell) ? array_diff_key($cell, ['parsed' => true]) : $cell, $cells);
    }

    private function readOf(SlipRead $slip): ?DocumentRead
    {
        return DocumentRead::withoutGlobalScopes()->where('organization_id', $slip->organization_id)->find($slip->document_read_id);
    }

    private function pageOf(DocumentRead $read): ?DocumentReadPage
    {
        return DocumentReadPage::withoutGlobalScopes()->where('organization_id', $read->organization_id)->where('document_read_id', $read->id)
            ->where('page_no', 1)->where('part', ReadIntake::FULL)->orderBy('id')->first();
    }
}
