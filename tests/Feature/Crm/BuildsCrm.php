<?php

namespace Tests\Feature\Crm;

use App\Models\CrmLead;
use App\Models\CrmTender;
use App\Models\CrmTenderBoqItem;
use App\Models\CrmTenderDocument;
use App\Models\CrmTenderPayment;
use App\Models\Organization;
use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Str;

trait BuildsCrm
{
    protected function makeTender(Organization $organization, array $overrides = []): CrmTender
    {
        return CrmTender::create([
            'organization_id' => $organization->id,
            'title' => 'Road widening, NH-48',
            'authority' => 'PWD Pune',
            'stage' => 'enquiry',
            'estimated_value' => '50000000.00',
            ...$overrides,
        ]);
    }

    protected function makeLead(Organization $organization, array $overrides = []): CrmLead
    {
        return CrmLead::create([
            'organization_id' => $organization->id,
            'name' => 'Ramesh Kulkarni',
            'company_name' => 'Kulkarni Developers',
            'phone' => '9876543210',
            'email' => 'ramesh@example.test',
            'source' => 'referral',
            'status' => 'new',
            ...$overrides,
        ]);
    }

    protected function makeParty(Organization $organization, array $overrides = []): Party
    {
        return Party::create([
            'organization_id' => $organization->id,
            'type' => 'client',
            'name' => 'PWD Pune',
            'contact_person' => 'Mr. Deshmukh',
            'phone' => '9822012345',
            'email' => 'pwd@example.test',
            ...$overrides,
        ]);
    }

    protected function makePayment(CrmTender $tender, array $overrides = []): CrmTenderPayment
    {
        return CrmTenderPayment::create([
            'organization_id' => $tender->organization_id,
            'tender_id' => $tender->id,
            'kind' => 'emd',
            'amount' => '500000.00',
            'mode' => 'online',
            'status' => 'paid',
            'paid_on' => today(),
            'refundable' => true,
            ...$overrides,
        ]);
    }

    protected function makeBoqLine(CrmTender $tender, array $overrides = []): CrmTenderBoqItem
    {
        return CrmTenderBoqItem::create([
            'organization_id' => $tender->organization_id,
            'tender_id' => $tender->id,
            'uuid' => (string) Str::uuid(),
            'section' => 'Earthwork',
            'code' => '1.1',
            'description' => 'Excavation in all soils',
            'unit' => 'cum',
            'qty' => '250.000',
            'rate' => '185.00',
            'sort' => 1,
            ...$overrides,
        ]);
    }

    protected function makeTenderDocument(CrmTender $tender, array $overrides = []): CrmTenderDocument
    {
        return CrmTenderDocument::create([
            'organization_id' => $tender->organization_id,
            'tender_id' => $tender->id,
            'title' => 'Tender notice',
            'kind' => 'nit',
            'path' => 'org-'.$tender->organization_id.'/crm-tenders/'.$tender->id.'/nit.pdf',
            'original_name' => 'nit.pdf',
            'size' => 2048,
            ...$overrides,
        ]);
    }

    /** A signed-in member of a company on a plan that includes the CRM. */
    protected function crmUser(string $role = 'owner', string $plan = 'business', string $name = 'Acme Infra'): User
    {
        $user = $this->member($this->tenant($name, $plan), $role);
        $this->actingAs($user);

        return $user;
    }
}
