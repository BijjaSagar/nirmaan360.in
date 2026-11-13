<?php

use App\Models\BoqScanRow;
use App\Models\DocumentRead;
use App\Services\BoqScan\BoqReadKind;
use App\Services\DocumentReading\AiDocumentConsent;

/*
 * The scanned BOQ reader with one read of the company's project ready for review: a line read with
 * the BOQ rate and its amount, so the review screen and its JSON carry rupee figures. The walks check
 * that the owner gets the review and that no role without money.view gets a figure of it.
 *
 * The module registers its reader kind only once its screens are in place, so the walk registers it
 * here, as a company that uses the reader would have it.
 */
return function (): void {
    config(['modules.boq_scan.read_kinds' => [BoqReadKind::class]]);

    $read = DocumentRead::create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'kind' => BoqReadKind::KEY,
        'status' => DocumentRead::READY,
        'user_id' => $this->users['owner']->id,
        'pages_total' => 1,
        'pages_read' => 1,
        'consent_version' => AiDocumentConsent::VERSION,
        'options' => ['language' => 'en'],
    ]);

    BoqScanRow::create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'document_read_id' => $read->id,
        'page_no' => 1,
        'row_no' => 1,
        'sort' => 100100,
        'kind' => BoqScanRow::ITEM,
        'kind_read' => BoqScanRow::ITEM,
        'item_no_raw' => 'S1',
        'description_raw' => 'Scanned line for the walk',
        'unit_raw' => 'cum',
        'qty_raw' => '1.000',
        'rate_raw' => self::MONEY['boq_rate'],
        'amount_raw' => self::MONEY['boq_rate'],
        'states' => ['item_no' => 'read', 'description' => 'read', 'unit' => 'read', 'qty' => 'read', 'rate' => 'read', 'amount' => 'read'],
        'scripts' => ['qty' => 'ascii', 'rate' => 'ascii', 'amount' => 'ascii'],
        'y_top' => 100,
        'y_bottom' => 130,
        'code' => 'S1',
        'description' => 'Scanned line for the walk',
        'unit' => 'cum',
        'qty' => '1.000',
        'rate' => self::MONEY['boq_rate'],
        'amount' => self::MONEY['boq_rate'],
        'initial' => ['qty' => '1.000', 'rate' => self::MONEY['boq_rate'], 'amount' => self::MONEY['boq_rate'], 'instruction_like' => []],
        'typed' => [],
    ]);

    $this->rows[DocumentRead::class] ??= $read;
    $this->routeParams['boq-scans.show'] = ['read' => $read];
    $this->routeParams['boq-scans.rows'] = ['read' => $read];
    $this->routeQueries['boq-scans.rows'] = ['page_no' => 1];
    $this->routeParams['api.boq-scans.show'] = ['read' => $read];
};
