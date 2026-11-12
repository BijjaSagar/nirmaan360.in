<?php

use App\Models\BoqItem;
use App\Models\ClientPaymentStage;
use App\Models\ClientQuotation;
use App\Models\Dpr;
use App\Models\DprPhoto;
use App\Models\DprWorkItem;
use App\Models\OwnerUpdate;
use App\Services\OwnerPack\OwnerUpdateDocument;
use App\Services\OwnerPack\OwnerUpdateService;
use App\Services\OwnerPack\OwnerUpdateShareService;
use App\Services\OwnerPack\QuotationDocument;
use App\Services\OwnerPack\QuotationService;
use App\Services\OwnerPack\QuotationShareService;
use Illuminate\Support\Facades\Storage;

/*
 * Home-owner pack: one quotation made from the company's BOQ line, with two payment stages, issued
 * and shared. Its rate is the BOQ's sentinel figure, so the quotation screens leak it to anyone
 * they open for. They need money.view, so the walk must find them closed to every site role.
 *
 * The shared page is given the real id and token of that open link. The walk visits it without the
 * signature, as a signed-in member of the company, and must be refused.
 *
 * Then one weekly update for a week with an approved daily report and a photo, stating the payment
 * position on that quotation, approved and shared. It carries the same figure, so the walk must
 * find its page closed to every site role and its list free of money. Its shared page and its photo
 * get the real id, token and photo id, and are visited without their signatures.
 */
return function (): void {
    $service = app(QuotationService::class);
    $owner = $this->users['owner'];

    $quotation = $service->create($this->project, [
        'client_name' => 'Kalyani Developers',
        'language' => 'en',
        'gst_pct' => null,
        'boq_item_ids' => [$this->rows[BoqItem::class]->id],
    ], $owner);

    foreach ([['On agreement', '40'], ['On handing over', '60']] as [$title, $percent]) {
        $service->addStage($quotation, ['title' => $title, 'basis' => ClientPaymentStage::PERCENT, 'percent' => $percent], $owner);
    }

    [$sheet, $note] = $service->review($quotation->fresh());
    $quotation = $service->issue($quotation, [
        'fact_fingerprint' => $note->fingerprint,
        'number_ticks' => app(QuotationDocument::class)->ticksNeeded($note, $sheet),
    ], $owner);
    $quotation = app(QuotationShareService::class)->share($quotation, $owner);

    $this->rows[ClientQuotation::class] = $quotation;
    $this->routeParams['quotations.shared'] = ['id' => $quotation->id, 'token' => $quotation->share_token];

    // The first stage is reached, so the update has an amount to state.
    $service->markReached($quotation->stages()->firstOrFail(), ['reached' => true, 'reached_on' => '2026-11-03'], $owner);

    $report = Dpr::create(['organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'report_date' => '2026-11-03', 'status' => 'APPROVED', 'created_by' => $this->users['engineer']->id]);
    DprWorkItem::create(['organization_id' => $this->organization->id, 'dpr_id' => $report->id, 'boq_item_id' => $this->rows[BoqItem::class]->id, 'qty' => '3.000']);
    // A real, tiny picture: an update takes only photos that can be drawn again for a client.
    ob_start();
    imagejpeg(imagecreatetruecolor(8, 6));
    Storage::put('none/owner-update.jpg', (string) ob_get_clean());
    $photo = DprPhoto::create(['organization_id' => $this->organization->id, 'dpr_id' => $report->id, 'path' => 'none/owner-update.jpg', 'caption' => 'Shuttering in place']);

    $updates = app(OwnerUpdateService::class);
    $update = $updates->create($this->project, [
        'week_start' => '2026-11-02',
        'language' => 'en',
        'show_money' => true,
        'quotation_id' => $quotation->id,
        'received_amount' => '1.00',
        'photo_ids' => [$photo->id],
    ], $owner);

    [$sheet, $note] = $updates->review($update);
    $update = $updates->approve($update, [
        'fact_fingerprint' => $note->fingerprint,
        'number_ticks' => app(OwnerUpdateDocument::class)->ticksNeeded($note, $sheet),
    ], $owner);
    $update = app(OwnerUpdateShareService::class)->share($update, $owner);

    $this->rows[OwnerUpdate::class] = $update;
    $this->routeParams['owner-updates.shared'] = ['id' => $update->id, 'token' => $update->share_token];
    $this->routeParams['owner-updates.shared.photo'] = ['id' => $update->id, 'token' => $update->share_token, 'photo' => $photo->id];
};
