<?php

use App\Models\CrmTender;
use App\Models\CrmTenderDocument;
use App\Models\CrmTenderFact;
use App\Models\DocumentRead;
use App\Services\DocumentReading\AiDocumentConsent;
use Illuminate\Support\Facades\Storage;

/*
 * A tender of the money world read by the tender reader: its notice, a finished read and one fact
 * on its sheet carrying the tender's own value. The reading screen is walked with this read, not
 * with the shared one another file may have filed, because it shows tender reads only.
 *
 * The sheet and the reading screen need crm.view, which no site role holds, so the walks as site
 * roles are refused; the owner's walk sees both pages working.
 */
return function (): void {
    $tender = $this->rows[CrmTender::class];
    $path = 'org-'.$this->organization->id.'/crm-tenders/'.$tender->id.'/notice.pdf';
    Storage::put($path, "%PDF-1.4\n%%EOF\n");

    $document = CrmTenderDocument::create([
        'organization_id' => $this->organization->id,
        'tender_id' => $tender->id,
        'title' => 'Notice inviting tender',
        'kind' => 'nit',
        'path' => $path,
        'original_name' => 'notice.pdf',
        'mime' => 'application/pdf',
        'size' => 16,
        'uploaded_by' => $this->users['owner']->id,
    ]);

    $read = DocumentRead::create([
        'organization_id' => $this->organization->id,
        'kind' => 'tender',
        'status' => DocumentRead::READY,
        'user_id' => $this->users['owner']->id,
        'subject_type' => $document->getMorphClass(),
        'subject_id' => $document->id,
        'pages_total' => 1,
        'pages_read' => 1,
        'started_at' => now(),
        'consent_version' => AiDocumentConsent::VERSION,
        'options' => ['tender_id' => $tender->id, 'document_id' => $document->id, 'language' => 'en', 'matches' => true, 'page_flags' => [], 'letters' => 100, 'devanagari' => 0],
    ]);

    CrmTenderFact::create([
        'organization_id' => $this->organization->id,
        'tender_id' => $tender->id,
        'document_id' => $document->id,
        'document_read_id' => $read->id,
        'field' => 'estimated_value',
        'value_raw' => 'Rs. '.self::MONEY['tender_value'].'/-',
        'value_norm' => self::MONEY['tender_value'],
        'value_kind' => 'amount',
        'page' => 1,
        'quote' => 'Estimated cost put to tender Rs. '.self::MONEY['tender_value'].'/-',
        'quote_line' => 'Estimated cost put to tender Rs. '.self::MONEY['tender_value'].'/-',
        'quote_found' => true,
        'source' => 'text',
    ]);

    $this->routeParams['tender-reads.view'] = ['read' => $read->id];
};
