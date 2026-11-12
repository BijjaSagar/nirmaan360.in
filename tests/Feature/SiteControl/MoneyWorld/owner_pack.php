<?php

use App\Models\BoqItem;
use App\Models\ClientPaymentStage;
use App\Models\ClientQuotation;
use App\Services\OwnerPack\QuotationDocument;
use App\Services\OwnerPack\QuotationService;
use App\Services\OwnerPack\QuotationShareService;

/*
 * Home-owner pack: one quotation made from the company's BOQ line, with two payment stages, issued
 * and shared. Its rate is the BOQ's sentinel figure, so the quotation screens leak it to anyone
 * they open for. They need money.view, so the walk must find them closed to every site role.
 *
 * The shared page is given the real id and token of that open link. The walk visits it without the
 * signature, as a signed-in member of the company, and must be refused.
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
};
