<?php

namespace App\Services\TenderReading;

use App\Models\CrmTender;
use App\Models\CrmTenderDocument;
use App\Models\CrmTenderFact;
use App\Models\DocumentRead;
use App\Models\User;
use App\Services\DocumentReading\AiSpend;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * The tender reader's card on the tender page: the sheet when there is one, and "Read this document"
 * beside each PDF, JPG or PNG for someone who may start a read while the reader is on for the company.
 */
class TenderCard
{
    public function __construct(private AiSpend $spend) {}

    /**
     * @param  Collection<int, CrmTenderDocument>  $documents  The tender's documents, as the tender page lists them.
     * @return array<string, mixed>|null Null when there is nothing to show.
     */
    public function for(CrmTender $tender, User $viewer, Collection $documents): ?array
    {
        if (! Route::has('tender-reads.view') || ! $viewer->can('crm.view')) {
            return null;
        }

        $facts = CrmTenderFact::withoutGlobalScopes()->where('organization_id', $tender->organization_id)->where('tender_id', $tender->id)
            ->whereIn('status', CrmTenderFact::STANDING)->get(['status', 'conflict_group']);

        $reads = $documents->isEmpty() ? collect() : DocumentRead::withoutGlobalScopes()
            ->where('organization_id', $tender->organization_id)
            ->where('kind', TenderReadKind::KEY)
            ->where('subject_type', (new CrmTenderDocument)->getMorphClass())
            ->whereIn('subject_id', $documents->modelKeys())
            ->orderBy('id')->get(['id', 'subject_id', 'status', 'created_at'])
            // The newest read of each document: later rows replace earlier ones under the same key.
            ->keyBy('subject_id');

        $canRead = Route::has('tender-reads.store')
            && $viewer->can('crm.manage') && $viewer->can('agents.use')
            && $viewer->organization !== null && $this->spend->readersOn($viewer->organization, TenderReadKind::KEY);

        $rows = $documents->map(fn (CrmTenderDocument $document) => [
            'id' => $document->id,
            'title' => $document->title,
            'readable' => self::looksLikePdf($document) || self::looksLikePicture($document),
            'read' => $reads->get($document->id),
        ])->filter(fn (array $row) => $row['read'] !== null || ($canRead && $row['readable']))->values();

        if ($facts->isEmpty() && $rows->isEmpty()) {
            return null;
        }

        return [
            'can_read' => $canRead,
            'documents' => $rows->all(),
            'proposed' => $facts->where('status', CrmTenderFact::PROPOSED)->count(),
            'confirmed' => $facts->where('status', CrmTenderFact::CONFIRMED)->count(),
            'conflicts' => $facts->pluck('conflict_group')->filter()->uniqueStrict()->count(),
        ];
    }

    /** By the name and type the upload gave it. The read checks the file's content before anything else. */
    public static function looksLikePdf(CrmTenderDocument $document): bool
    {
        return strtolower(pathinfo((string) $document->original_name, PATHINFO_EXTENSION)) === 'pdf'
            || $document->mime === 'application/pdf';
    }

    /** A JPG or PNG, by the name the upload gave it. It is read as one page picture. The read checks the content first. */
    public static function looksLikePicture(CrmTenderDocument $document): bool
    {
        return in_array(strtolower(pathinfo((string) $document->original_name, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true);
    }
}
