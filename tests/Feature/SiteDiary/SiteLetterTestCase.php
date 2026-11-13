<?php

namespace Tests\Feature\SiteDiary;

use App\Models\ClientReceipt;
use App\Models\ContractTerm;
use App\Models\Hindrance;
use App\Models\Party;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\RaBillDeduction;
use App\Models\SiteLetter;
use App\Models\User;
use App\Services\SiteDiary\SiteLetters;
use Illuminate\Support\Str;

/**
 * The invented company of the register tests, with what letters are drafted from: agreement details
 * a person typed and confirmed, register entries resting on daily reports, and client bills with
 * receipts. Every date, number of days, amount, bill number and clause here is made up for the test
 * and comes from no agreement and no law.
 */
abstract class SiteLetterTestCase extends SiteDiaryTestCase
{
    protected const EOT = SiteLetter::EOT_REQUEST;

    protected const DELAY = SiteLetter::DELAY_NOTICE;

    protected const PAYMENT = SiteLetter::PAYMENT_FOLLOWUP;

    /** A confirmed agreement detail of the client contract. */
    protected function reference(string $code, array $attributes = [], ?Project $project = null): ContractTerm
    {
        return $this->notice([
            'kind' => 'reference', 'code' => $code, 'label' => config("modules.contract_terms.codes.reference.{$code}"),
            'days' => null, 'counted_from' => null, 'source_ref' => 'Sample agreement, front sheet', ...$attributes,
        ], $project);
    }

    /** Agreement number, agreement date and a stipulated completion date, all confirmed. */
    protected function agreement(string $completion = '2026-08-31', ?Project $project = null): void
    {
        $this->reference('agreement_no', ['value_text' => 'SG/LVR/07'], $project);
        $this->reference('agreement_date', ['value_date' => '2025-09-15'], $project);
        $this->reference('stipulated_completion', ['value_date' => $completion], $project);
    }

    /**
     * A confirmed register entry with one cited daily report for each given date.
     *
     * @param  list<string>|null  $reports  Report dates. Null cites one report on the first day.
     */
    protected function entry(string $from, ?string $to, array $attributes = [], ?array $reports = null, ?Project $project = null): Hindrance
    {
        $entry = $this->hindrance(['started_on' => $from, 'ended_on' => $to, ...$attributes], $project);

        foreach ($reports ?? [$from] as $date) {
            $this->cite($entry, $this->dpr($date, 'Work held up: '.$entry->description, [], $project));
        }

        return $entry->refresh();
    }

    /** An approved client bill, written straight to the table. $tds is TDS shown as a deduction on the bill itself. */
    protected function clientBill(string $number, string $net, array $attributes = [], string $tds = '0', ?Project $project = null): RaBill
    {
        $project ??= $this->project;
        $client = Party::withoutGlobalScopes()->firstOrCreate(['organization_id' => $project->organization_id, 'type' => 'client', 'name' => 'Sahyadri Estates']);

        $bill = RaBill::withoutGlobalScopes()->create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'party_id' => $client->id, 'type' => 'client',
            'bill_no' => RaBill::withoutGlobalScopes()->where('project_id', $project->id)->count() + 1,
            'number' => $number, 'period_from' => '2026-01-01', 'period_to' => '2026-01-31',
            'status' => 'APPROVED', 'approved_at' => '2026-02-10 10:00:00',
            'gross_amount' => $net, 'deduction_total' => $tds, 'gst_amount' => '0', 'net_payable' => $net,
            ...$attributes,
        ]);

        if ($tds !== '0') {
            RaBillDeduction::withoutGlobalScopes()->create(['organization_id' => $bill->organization_id, 'ra_bill_id' => $bill->id, 'type' => 'tds', 'label' => 'TDS', 'pct' => '0', 'amount' => $tds]);
        }

        return $bill;
    }

    protected function receipt(RaBill $bill, string $amount, string $tds = '0'): ClientReceipt
    {
        return ClientReceipt::withoutGlobalScopes()->create([
            'organization_id' => $bill->organization_id, 'project_id' => $bill->project_id, 'party_id' => $bill->party_id, 'ra_bill_id' => $bill->id,
            'received_on' => '2026-03-01', 'amount' => $amount, 'tds_amount' => $tds, 'mode' => 'bank', 'status' => 'POSTED',
        ]);
    }

    /** What the form sends to prepare a letter. */
    protected function letterForm(string $type, array $overrides = []): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'type' => $type, 'language' => 'en', ...$overrides];
    }

    /** A draft prepared through the service, as that person. */
    protected function draft(string $type, array $data = [], ?User $by = null, ?Project $project = null): SiteLetter
    {
        $by ??= $this->pm;
        $this->actingAs($by);

        return app(SiteLetters::class)->create($project ?? $this->project, $this->letterForm($type, $data), $by);
    }

    /** @return list<string> Everything that must be ticked before this draft can be finalised. */
    protected function ticks(SiteLetter $letter): array
    {
        $review = app(SiteLetters::class)->review($letter->refresh());

        return [...array_column($review['figures'], 'key'), ...array_column($review['typed'], 'id')];
    }

    /** The plain text of a page, tags removed and spaces collapsed, for reading a sentence that carries marked figures. */
    protected function words(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
    }
}
