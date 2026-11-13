<?php

namespace App\Services\OwnerPack;

use App\Models\ClientPaymentStage;
use App\Models\ClientQuotation;
use App\Models\ClientQuotationItem;
use App\Models\Concerns\OrganizationScope;
use App\Models\Concerns\ProjectAccessScope;
use App\Models\Dpr;
use App\Models\DprPhoto;
use App\Models\Organization;
use App\Models\OwnerUpdate;
use App\Models\Project;
use App\Services\Wording\FactSheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Loads what a shared link may show. The visitor has no account here, or is signed in to some other
 * company, so nothing may rest on the tenant scopes: they follow whoever is signed in. Every query
 * drops the two tenant scopes and is limited by hand to the company and the project of the row the
 * link names. The company's plan is checked here too, because no sign-in middleware runs.
 *
 * Every reason a link does not open gives the same answer, null, so the page says the same thing
 * for a withdrawn link, a replaced one, one past its date and one that never existed.
 */
final class SharedPack
{
    /** The plan feature a company needs for its shared pages to open. */
    public const FEATURE = 'owner_pack';

    public function __construct(private QuotationDocument $document, private OwnerUpdateDocument $updates) {}

    public function quotation(int $id, string $token): ?SharedQuotation
    {
        /** @var ?ClientQuotation $quotation */
        $quotation = $this->open(ClientQuotation::class)->find($id);

        if ($quotation === null || ! $quotation->isIssued() || ! $this->linkIsLive($quotation, $token)) {
            return null;
        }

        $company = $this->company($quotation->organization_id);
        $project = $this->project($quotation->organization_id, $quotation->project_id);

        if ($company === null || $project === null) {
            return null;
        }

        $newer = $this->within(ClientQuotation::class, $quotation)
            ->where('number', $quotation->number)
            ->where('status', ClientQuotation::ISSUED)
            ->where('revision', '>', $quotation->revision)
            ->max('revision');
        $replacedBy = $newer === null ? null : (int) $newer;

        try {
            // The facts a person ticked when the quotation was issued, not figures worked out again for a visitor.
            $sheet = FactSheet::fromArray($quotation->facts ?? []);

            if (! $sheet->matches($quotation->fact_fingerprint)) {
                return null;
            }

            $document = $this->document->render($quotation->language, $sheet, $quotation->slots, withSchedule: $replacedBy === null);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $lines = fn (string $model) => $this->within($model, $quotation)->where('quotation_id', $quotation->id)->orderBy('sort')->orderBy('id')->get();

        return new SharedQuotation(
            $quotation,
            (string) $company->name,
            (string) $project->name,
            $lines(ClientQuotationItem::class),
            $replacedBy === null ? $lines(ClientPaymentStage::class) : collect(),
            $document,
            $replacedBy,
        );
    }

    /**
     * A weekly update as it was approved: the text a named person approved, the work rows and the
     * photos of the facts kept with it. Nothing is read from the daily reports again for a visitor.
     */
    public function update(int $id, string $token): ?SharedUpdate
    {
        /** @var ?OwnerUpdate $update */
        $update = $this->open(OwnerUpdate::class)->find($id);

        if ($update === null || $update->status !== OwnerUpdate::SHARED || $update->approved_at === null || ! $this->linkIsLive($update, $token)) {
            return null;
        }

        $company = $this->company($update->organization_id);
        $project = $this->project($update->organization_id, $update->project_id);
        $body = array_values(array_filter((array) $update->body_rendered, fn ($slot) => is_array($slot) && is_string($slot['id'] ?? null) && is_string($slot['text'] ?? null)));

        if ($company === null || $project === null || $body === []) {
            return null;
        }

        try {
            // The facts a person ticked when the update was approved, not figures read again for a visitor.
            $sheet = FactSheet::fromArray($update->facts ?? []);

            if (! $sheet->matches($update->fact_fingerprint)) {
                return null;
            }

            $work = $this->updates->workLines($sheet, $update->language);
            $listed = $this->updates->photos($sheet, $update->language);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $replacedBy = null;

        if ($update->show_money) {
            /** @var ?ClientQuotation $quotation */
            $quotation = $update->quotation_id === null ? null : $this->within(ClientQuotation::class, $update)->whereKey($update->quotation_id)->first();

            // A payment position without its quotation has nothing to stand on.
            if ($quotation === null || ! $quotation->isIssued()) {
                return null;
            }

            $newer = $this->within(ClientQuotation::class, $update)
                ->where('number', $quotation->number)
                ->where('status', ClientQuotation::ISSUED)
                ->where('revision', '>', $quotation->revision)
                ->max('revision');
            $replacedBy = $newer === null ? null : (int) $newer;
        }

        // Once the quotation was replaced, the payment position stated on the old revision is no longer shown.
        if ($replacedBy !== null) {
            $body = array_values(array_filter($body, fn (array $slot) => ! OwnerUpdateDocument::isMoneySlot($slot['id'])));
        }

        // A photo is shown only while it is one the update names, and its row still belongs to a report of this project.
        $reports = $this->within(Dpr::class, $update)->select('id');
        $kept = $this->open(DprPhoto::class)
            ->where('organization_id', $update->organization_id)
            ->whereIn('id', array_intersect($update->photoIds(), array_column($listed, 'id')))
            ->whereIn('dpr_id', $reports)
            ->get(['id', 'path'])
            ->keyBy('id');

        return new SharedUpdate(
            $update,
            (string) $company->name,
            (string) $project->name,
            $body,
            $work,
            array_values(array_filter($listed, fn (array $photo) => isset($kept[$photo['id']]))),
            $kept->map(fn (DprPhoto $photo) => (string) $photo->path)->all(),
            $replacedBy,
        );
    }

    /**
     * Whether the token in the address is the one kept on the row, not withdrawn and not past its date.
     * The row needs the columns share_token, share_expires_at and share_revoked_at.
     */
    public function linkIsLive(Model $row, string $token): bool
    {
        $kept = $row->getAttribute('share_token');
        $until = $row->getAttribute('share_expires_at');

        return is_string($kept) && $kept !== '' && hash_equals($kept, $token)
            && $row->getAttribute('share_revoked_at') === null
            && $until !== null && now()->lessThan($until);
    }

    /** The company, when its plan still includes the pack. */
    public function company(int $organizationId): ?Organization
    {
        $company = Organization::find($organizationId);

        return $company !== null && $company->allowsFeature(self::FEATURE) ? $company : null;
    }

    /** The project, when it still exists in that company. */
    public function project(int $organizationId, int $projectId): ?Project
    {
        return $this->open(Project::class)->where('organization_id', $organizationId)->whereKey($projectId)->first();
    }

    /**
     * A query on a tenant model without the two scopes that follow the signed-in person. Soft deletes
     * still apply. Whoever calls this adds the company and project limits by hand.
     *
     * @param  class-string<Model>  $model
     */
    public function open(string $model): Builder
    {
        return $model::withoutGlobalScopes([OrganizationScope::class, ProjectAccessScope::class]);
    }

    /**
     * The same, limited to the company and the project of a row.
     *
     * @param  class-string<Model>  $model
     */
    public function within(string $model, Model $row): Builder
    {
        return $this->open($model)
            ->where('organization_id', $row->getAttribute('organization_id'))
            ->where('project_id', $row->getAttribute('project_id'));
    }
}
