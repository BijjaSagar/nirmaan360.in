<?php

use App\Models\ContractTerm;
use App\Models\Dpr;
use App\Models\Hindrance;
use App\Models\HindranceSource;
use App\Models\SiteLetter;
use App\Services\SiteDiary\SiteLetters;
use Illuminate\Support\Str;

/*
 * The hindrance register for the route walks: a daily report that says something under hindrances,
 * a confirmed entry made from it that is still going on, and a confirmed notice period of its own,
 * so the page works out a date and prints the period it came from. Nothing in the register is money.
 * A draft letter about the entry lets the walks open the letter page as well.
 * The clause typed for the term is the one thing a site role must not be shown.
 * The number of days is invented for the test and comes from no agreement.
 */
return function (): void {
    $owner = $this->users['owner'];
    $common = ['organization_id' => $this->organization->id, 'project_id' => $this->project->id];

    $report = Dpr::create([
        ...$common,
        'report_date' => now()->subDays(3)->toDateString(),
        'hindrances' => 'Lift shaft drawings not received',
        'status' => 'DRAFT',
        'created_by' => $this->users['engineer']->id,
    ]);

    $entry = Hindrance::create([
        ...$common,
        'category' => 'drawings_or_decisions',
        'description' => 'Lift shaft drawings awaited',
        'started_on' => $report->report_date->toDateString(),
        'status' => Hindrance::CONFIRMED,
        'proposed_by' => Hindrance::BY_PERSON,
        'confirmed_by' => $owner->id,
        'confirmed_at' => now(),
    ]);

    HindranceSource::create([
        ...$common,
        'hindrance_id' => $entry->id,
        'dpr_id' => $report->id,
        'report_date' => $report->report_date->toDateString(),
        'excerpt' => $report->hindrances,
        'dpr_status' => $report->status,
    ]);

    ContractTerm::create([
        ...$common,
        'kind' => 'notice', 'code' => 'eot_notice', 'label' => 'Notice for extension of time',
        'days' => 6, 'counted_from' => 'hindrance_start',
        'effective_from' => '2026-01-01',
        'source_ref' => 'Sample agreement, annexure Q',
        'entered_by' => $owner->id, 'confirmed_by' => $owner->id, 'confirmed_at' => now(),
    ]);

    $this->rows[Hindrance::class] ??= $entry;

    // A draft notice of delay about that entry, in Marathi, prepared the way the screen prepares one. A letter of this
    // kind carries no money; a payment follow-up needs an approved client bill, which this company does not have.
    $this->rows[SiteLetter::class] ??= app(SiteLetters::class)->create($this->project, [
        'client_uuid' => (string) Str::uuid(),
        'type' => SiteLetter::DELAY_NOTICE,
        'language' => 'mr',
        'hindrance_ids' => [$entry->id],
        'addressed_to' => 'The Engineer in charge',
    ], $owner);
};
