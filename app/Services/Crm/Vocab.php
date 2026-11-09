<?php

namespace App\Services\Crm;

/** The words shown for each stored code. Every label goes through __() so it can be translated. */
final class Vocab
{
    /** @return array<string,string> */
    public static function stages(): array
    {
        return [
            'enquiry' => __('Enquiry'),
            'bidding' => __('Bidding'),
            'submitted' => __('Submitted'),
            'won' => __('Won'),
            'lost' => __('Lost'),
            'no_bid' => __('No bid'),
        ];
    }

    /** @return array<string,string> */
    public static function sources(): array
    {
        return [
            'website' => __('Website'),
            'meta_ads' => __('Meta ads'),
            'referral' => __('Referral'),
            'walk_in' => __('Walk-in'),
            'tender_portal' => __('Tender portal'),
            'cold_call' => __('Cold call'),
            'other' => __('Other'),
        ];
    }

    /** @return array<string,string> */
    public static function leadStatuses(): array
    {
        return [
            'new' => __('New'),
            'contacted' => __('Contacted'),
            'qualified' => __('Qualified'),
            'converted' => __('Converted'),
            'lost' => __('Lost'),
        ];
    }

    /** @return array<string,string> */
    public static function paymentKinds(): array
    {
        return [
            'emd' => __('EMD'),
            'tender_fee' => __('Tender fee'),
            'processing_fee' => __('Processing fee'),
            'other' => __('Other'),
        ];
    }

    /** @return array<string,string> */
    public static function paymentModes(): array
    {
        return [
            'online' => __('Online'),
            'dd' => __('Demand draft'),
            'bg' => __('Bank guarantee'),
            'fdr' => __('FDR'),
            'cheque' => __('Cheque'),
            'cash' => __('Cash'),
            'other' => __('Other'),
        ];
    }

    /** @return array<string,string> */
    public static function paymentStatuses(): array
    {
        return [
            'planned' => __('To pay'),
            'paid' => __('Paid'),
            'refunded' => __('Refunded'),
            'forfeited' => __('Forfeited'),
            'adjusted' => __('Adjusted'),
        ];
    }

    /** @return array<string,string> */
    public static function documentKinds(): array
    {
        return [
            'nit' => __('Tender notice'),
            'corrigendum' => __('Corrigendum'),
            'boq' => __('BOQ'),
            'drawing' => __('Drawing'),
            'eligibility' => __('Eligibility papers'),
            'bid' => __('Our bid'),
            'receipt' => __('Receipt'),
            'other' => __('Other'),
        ];
    }

    /** @return array<string,string> */
    public static function activityKinds(): array
    {
        return [
            'note' => __('Note'),
            'call' => __('Call'),
            'meeting' => __('Meeting'),
            'email' => __('Email'),
            'whatsapp' => __('WhatsApp'),
            'stage' => __('Stage'),
            'system' => __('System'),
        ];
    }

    /** @return array<string,string> */
    public static function entities(): array
    {
        return ['lead' => __('Leads'), 'company' => __('Companies'), 'tender' => __('Tenders')];
    }

    /** @return array<string,string> */
    public static function fieldTypes(): array
    {
        return ['text' => __('Text'), 'number' => __('Number'), 'date' => __('Date'), 'select' => __('Choice')];
    }

    /** @return array<string,string> */
    public static function channels(): array
    {
        return ['whatsapp' => __('WhatsApp'), 'email' => __('Email')];
    }

    /** @return array<string,string> */
    public static function reasonKinds(): array
    {
        return ['won' => __('Why we won'), 'lost' => __('Why we lost'), 'no_bid' => __('Why we did not bid')];
    }

    /** @return array<string,string> */
    public static function partyTypes(): array
    {
        return [
            'client' => __('Client'),
            'consultant' => __('Consultant'),
            'subcontractor' => __('Subcontractor'),
            'vendor' => __('Vendor'),
        ];
    }
}
