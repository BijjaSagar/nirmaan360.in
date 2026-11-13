<?php

use App\Models\DocumentRead;
use App\Models\SlipRead;
use App\Services\DocumentReading\AiDocumentConsent;
use Illuminate\Support\Str;

/*
 * Challan photo reading: one finished reading of a delivery challan, taken by the store keeper on the
 * company's project, as the form would find it before saving. A challan reading has no money field,
 * so its answer carries no figure the walks look for; the store keeper, the owner and the admin may
 * open it, and everyone else is refused.
 */
return function (): void {
    $store = $this->users['store'];
    $cell = fn (string $raw, ?string $value) => ['raw' => $raw, 'value' => $value, 'state' => 'read', 'warnings' => []];

    $read = DocumentRead::create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'kind' => 'slip',
        'status' => DocumentRead::READY,
        'user_id' => $store->id,
        'pages_total' => 1,
        'pages_read' => 1,
        'consent_version' => AiDocumentConsent::VERSION,
        'finished_at' => now(),
    ]);

    $this->rows[SlipRead::class] = SlipRead::create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'document_read_id' => $read->id,
        'kind' => SlipRead::CHALLAN,
        'status' => SlipRead::READY,
        'doc_kind' => 'delivery_challan',
        'fields' => [
            'challan_no' => $cell('DC-0457', 'DC-0457'),
            'challan_date' => $cell('03/11/2026', '2026-11-03'),
            'vehicle_no' => $cell('MH 12 AB 1234', 'MH12AB1234'),
            'supplier_name' => $cell('Godavari Cement Traders', 'Godavari Cement Traders'),
        ],
        'lines' => [['description' => $cell('OPC 53 grade cement', 'OPC 53 grade cement'), 'qty' => $cell('120', '120.000'), 'unit' => [...$cell('Bags', 'bag'), 'known' => true, 'unit_id' => null]]],
        'warnings' => [],
        'suggestions' => ['party' => null, 'materials' => []],
        'image_sha256' => hash('sha256', 'money-world challan photo'),
        'user_id' => $store->id,
        'client_uuid' => (string) Str::uuid(),
    ]);
};
