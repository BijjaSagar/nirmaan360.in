<?php

namespace App\Services\BoqScan;

use App\Models\AiDocumentSettings;
use App\Models\DocumentRead;
use App\Models\Project;
use App\Models\User;
use App\Services\Agents\ModelGateway;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\ProviderBlock;
use App\Services\DocumentReading\ReadKinds;
use App\Services\DocumentReading\ReadStatus;
use App\Services\Reports\BoqPdfImport;
use App\Support\Money;
use Illuminate\Support\Facades\Route;

/**
 * What the web screens of the scanned BOQ reader show: the scan screen, the progress and review
 * screen of one read, and the offer on the project's BOQ in Excel page.
 *
 * The scan screen never fails with an error page. When a scan cannot be started it says why and
 * what to do, in the order the reader guard checks (RequireAiReader), so the sentence on the screen
 * is the one the guard would answer with. Looking at, correcting and confirming a read never depend
 * on any of this.
 */
class BoqScanScreen
{
    /** The browser's IndexedDB database that holds the kept copies (partials.kept-pdf), deleted whole at sign-out (partials.sign-out). */
    public const KEPT_DATABASE = 'nirmaan-boq-scan';

    /** Reads of a project listed on the scan screen, newest first. */
    private const LISTED = 10;

    /** Statuses of a read that a person can still go back to from the scan screen. */
    private const UNFINISHED = [...DocumentRead::OPEN, DocumentRead::READY, DocumentRead::FAILED];

    public function __construct(
        private AiSpend $spend,
        private ModelGateway $gateway,
        private ReadKinds $kinds,
        private ReadStatus $status,
        private BoqScanPresenter $presenter,
    ) {}

    /** Whether this module's reader is the one registered for BOQ reads. Until it is, the module is dormant. */
    public function installed(): bool
    {
        return $this->kinds->find(BoqReadKind::KEY) instanceof BoqReadKind;
    }

    /**
     * Whether the scan screen is there for this person's company at all: this module's reader is deployed and
     * the readers are on for the company (AiSpend::readersOn, the one test). While they are off the screen
     * answers as if it did not exist, as every other place that offers a reader hides it. A scan that cannot be
     * started for another reason (consent, a limit, the permission to use the AI agents) is explained there.
     */
    public function open(User $user): bool
    {
        $organization = $user->organization;

        return $organization !== null && $this->installed() && $this->spend->readersOn($organization, BoqReadKind::KEY);
    }

    /**
     * Whether the BOQ in Excel page offers this person the scan reader: the scan screen is there, the
     * readers are on for the company and its plan, and the person may both use the AI agents and work
     * with the money of the BOQ, as every route of the module asks.
     */
    public function offered(User $user): bool
    {
        $organization = $user->organization;

        return $organization !== null
            && Route::has('boq-scans.create')
            && $this->installed()
            && self::mayScan($user)
            && $this->spend->readersOn($organization, BoqReadKind::KEY);
    }

    /**
     * What the person needs to be offered a scan, whatever the state of the readers: a plan with the
     * reports module, the right to manage the BOQ and see its money, and the right to use the AI agents.
     * The refusal of a scanned PDF asks the same (BoqPdfReader::hasScanReader), so it never points
     * anyone to the scan reader who is not offered it.
     */
    public static function mayScan(User $user): bool
    {
        return (bool) $user->organization?->allowsFeature('reports')
            && $user->hasPermission('boq.manage')
            && Money::visible($user)
            && $user->hasPermission('agents.use');
    }

    /**
     * Why this person cannot start a scan now, with what to do about it; null when nothing stands
     * in the way. The checks and their sentences are those of the reader guard, in its order.
     *
     * @return ?array{code: string, message: string, settings: bool}
     */
    public function blocker(User $user): ?array
    {
        $organization = $user->organization;
        $stop = fn (string $code, string $message, bool $settings = false) => ['code' => $code, 'message' => $message, 'settings' => $settings];

        if ($organization === null || ! $this->installed() || ! $this->spend->released()) {
            return $stop('not_released', __('The readers are not switched on yet.'));
        }

        if (! $this->gateway->isConnected() || ProviderBlock::current() !== null) {
            return $stop('not_connected', __('The AI service is not available right now. Try again later.'));
        }

        if (! $this->spend->readerDeployed($organization, BoqReadKind::KEY)) {
            return $stop('not_released', __('The readers are not switched on yet.'));
        }

        if (AiDocumentSettings::withoutGlobalScopes()->where('organization_id', $organization->id)->value('platform_block')) {
            return $stop('company_limit', __('The readers are switched off for your company. Please contact support.'));
        }

        if (! AiDocumentConsent::current($organization)) {
            return $stop('consent_required', __('Your company has not agreed to the current notice for sending documents to the AI service. An owner or admin can do that in Settings.'), $user->hasPermission('settings.manage'));
        }

        if (! $user->hasPermission('agents.use')) {
            return $stop('agents_use', __('You can review and confirm scans, but starting one needs permission to use the AI agents. Ask an owner or admin.'));
        }

        if (($period = $this->spend->platformStop()) !== null) {
            return $stop('reader_paused', $period === 'monthly'
                ? __('The reader is paused for this month.')
                : __('The reader is paused for today. Try again tomorrow.'));
        }

        return null;
    }

    /**
     * Everything the scan screen of a project shows.
     *
     * @return array<string, mixed>
     */
    public function create(Project $project, User $user): array
    {
        return [
            'project' => $project,
            'blocker' => $this->blocker($user),
            'reads' => $this->unfinished($project),
            'limits' => $this->limits(),
            'keptKey' => self::keptKey($user, $project),
        ];
    }

    /**
     * Everything the screen of one read shows. Asking for the status also moves the read along, as
     * the status route does, so a read someone opens never waits for the scheduler.
     *
     * @return array<string, mixed>
     */
    public function show(DocumentRead $read, User $user): array
    {
        $status = $this->status->of($read);
        $read = DocumentRead::withoutGlobalScopes()->where('organization_id', $read->organization_id)->findOrFail($read->id);
        $page = null;

        if ($read->status === DocumentRead::READY) {
            $page = $this->presenter->page($read, 1);
            $first = collect($page['pages'])->first(fn (array $entry) => $entry['open'] > 0);

            if ($first !== null && (int) $first['page_no'] !== 1) {
                $page = $this->presenter->page($read, (int) $first['page_no']);
            }
        }

        return [
            'read' => $read,
            'project' => Project::withoutGlobalScopes()->where('organization_id', $read->organization_id)->find($read->project_id),
            'status' => $status,
            'page' => $page,
            'result' => $read->status === DocumentRead::CONFIRMED ? (array) (($read->review ?? [])['confirmed'] ?? []) : null,
            // Reading a page again or in halves sends it to the reader again.
            'canSend' => $user->hasPermission('agents.use'),
            'limits' => $this->limits(),
            'keptKey' => $read->project_id === null ? null : self::keptKey($user, $read->project_id),
        ];
    }

    /**
     * The reads of a project a person can still go back to: being prepared, running, paused,
     * waiting for review, or failed with pages that can be read again.
     *
     * @return list<DocumentRead>
     */
    public function unfinished(Project $project): array
    {
        return DocumentRead::where('project_id', $project->id)->where('kind', BoqReadKind::KEY)
            ->whereIn('status', self::UNFINISHED)->latest('id')->limit(self::LISTED)->get()->all();
    }

    /** @return array{pages: int, pdf_bytes: int, minutes: int} */
    public function limits(): array
    {
        return [
            'pages' => (int) $this->kinds->get(BoqReadKind::KEY)->limits()['max_pages'],
            'pdf_bytes' => (int) config('modules.document_reading.upload.source_kb') * 1024,
            // How long the browser uses its copy of a PDF uploaded on the BOQ in Excel page: as long as the preview of it lasts.
            // An older copy is deleted by the next page that carries the copy's script (partials.kept-pdf).
            'minutes' => BoqPdfImport::TTL_MINUTES,
        ];
    }

    /** Where a read stands, in a word or two for a list or a heading. */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            DocumentRead::DRAFT, DocumentRead::QUOTED => __('Not started'),
            DocumentRead::PREPARING, DocumentRead::PREPARED, DocumentRead::RUNNING => __('Reading'),
            DocumentRead::PAUSED => __('Paused'),
            DocumentRead::READY => __('Ready for review'),
            DocumentRead::FAILED => __('Could not be read'),
            DocumentRead::CONFIRMED => __('Written to the BOQ'),
            DocumentRead::DISCARDED => __('Discarded'),
            DocumentRead::CANCELLED => __('Cancelled'),
            DocumentRead::EXPIRED => __('Expired'),
            default => $status,
        };
    }

    /**
     * Where the browser keeps its own copy of the last PDF this person uploaded for a project, to
     * draw its pages beside the preview and to offer it to the scan screen. The copy never leaves
     * the browser; the name only keeps one person's copy apart from another's on a shared computer.
     */
    public static function keptKey(User $user, Project|int $project): string
    {
        return self::keptPrefix($user).($project instanceof Project ? $project->id : $project);
    }

    /** How every name of this person's kept copies begins. A copy under any other name was left by someone else. */
    public static function keptPrefix(User $user): string
    {
        return 'boq-pdf:'.$user->id.':';
    }
}
