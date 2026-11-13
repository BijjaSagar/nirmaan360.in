<?php

namespace App\Services\SiteDiary;

use App\Models\ContractTerm;
use App\Models\Hindrance;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\SiteLetter;
use App\Services\ContractTerms\ContractTerms;
use App\Services\Finance\SettlementService;
use App\Services\Wording\Fact;
use App\Services\Wording\FactSheet;
use App\Services\Wording\FactSource;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Reads the facts a letter may state from the records, as they stand on one day.
 *
 * Nothing here is typed into the code: a date or a number of days comes from the hindrance
 * register, an agreement detail from the contract terms a person entered and confirmed, an
 * amount from the client bills and the receipts recorded on them. The two things a person may
 * type on the letter itself, who it is addressed to and another completion date, are marked as
 * entered. The app works out three figures: the days of each hindrance, the days of all of them
 * with a shared day counted once, and the proposed completion date.
 *
 * The same records give the same sheet, fact for fact and in the same order, because the sheet's
 * fingerprint is compared when the letter is finalised. Every query names the company and the
 * project itself.
 */
class LetterFacts
{
    public function __construct(
        private Hindrances $hindrances,
        private HindranceRegister $register,
        private ContractTerms $terms,
        private SettlementService $settlement,
    ) {}

    /**
     * @param  array{hindrance_ids?: list<int|string>, bill_ids?: list<int|string>, addressed_to?: ?string, proposed_completion?: ?string}  $chosen
     * @param  CarbonInterface  $today  The date the letter carries. Hindrances still going on are counted up to it.
     *
     * @throws ValidationException When the records do not carry the letter: an entry that no longer counts, a bill with nothing outstanding, no completion date.
     */
    public function build(Project $project, string $type, array $chosen, CarbonInterface $today): FactSheet
    {
        $today = HindranceMath::day($today);
        $reference = $this->terms->effective($project, null, $today, 'reference');

        $facts = [
            $this->fact('letter_date', Fact::DATE, $today->toDateString(), FactSource::computed('letter_date')),
            ...$this->typed('addressed_to', Fact::TEXT, $chosen['addressed_to'] ?? null),
            $this->fact('project_name', Fact::TEXT, $project->name, FactSource::record('project', $project->id, 'name')),
            ...$this->agreement($reference),
        ];

        $facts = match ($type) {
            SiteLetter::EOT_REQUEST => [...$facts, ...$this->extensionOfTime($project, $reference, $chosen, $today)],
            SiteLetter::DELAY_NOTICE => [...$facts, ...$this->delay($project, $chosen, $today)],
            SiteLetter::PAYMENT_FOLLOWUP => [...$facts, ...$this->payment($project, $chosen, $today)],
        };

        $company = Organization::query()->findOrFail($project->organization_id);
        $facts[] = $this->fact('company_name', Fact::TEXT, $company->name, FactSource::record('organization', $company->id, 'name'));

        return new FactSheet($facts);
    }

    /** Whether a company's plan keeps the client bills and the receipts a payment follow-up is made from. */
    public function billsAreKept(Organization $organization): bool
    {
        return $organization->allowsFeature('billing') && $organization->allowsFeature('finance');
    }

    /**
     * The entries of a project a letter of this type may cite: confirmed, not corrected by a later
     * entry, started, and for a notice of delay still going on.
     *
     * @return Collection<int, Hindrance>
     */
    public function citable(Project $project, string $type, CarbonInterface $today): Collection
    {
        return $this->hindrances->standing($project)
            ->with('sources')
            ->whereDate('started_on', '<=', HindranceMath::day($today)->toDateString())
            ->when($type === SiteLetter::DELAY_NOTICE, fn ($entries) => $entries->whereNull('ended_on'))
            ->get();
    }

    /**
     * Client bills of the project with something still outstanding, oldest first, each with its position.
     * A bill marked paid on its RA bill page is left out even when no receipt adds up to it: the letter
     * never asks for money the company has said it was paid.
     *
     * @return Collection<int, array<string, mixed>> Keyed by bill id. See SettlementService::clientBillsOwed().
     */
    public function unpaidBills(Project $project): Collection
    {
        return $this->settlement->clientBillsOwed($project->organization_id, $project->id)
            ->keyBy(fn (array $row) => (int) $row['bill']->id);
    }

    /** The date a bill goes by: the day it was approved, or the end of its period. */
    public static function billDate(RaBill $bill): CarbonImmutable
    {
        return HindranceMath::day($bill->approved_at ?? $bill->period_to);
    }

    /**
     * @param  Collection<int, ContractTerm>  $reference  The confirmed agreement details in force on the letter's date.
     * @return list<Fact>
     */
    private function agreement(Collection $reference): array
    {
        $facts = [];
        $number = $this->single($reference, 'agreement_no');
        $date = $this->single($reference, 'agreement_date');

        if ($number !== null && filled($number->value_text)) {
            $facts[] = $this->fact('agreement_no', Fact::REFERENCE, $number->value_text, FactSource::record('contract_term', $number->id, 'value_text'));
        }

        if ($date?->value_date !== null) {
            $facts[] = $this->fact('agreement_date', Fact::DATE, $date->value_date->toDateString(), FactSource::record('contract_term', $date->id, 'value_date'));
        }

        return $facts;
    }

    /**
     * @param  Collection<int, ContractTerm>  $reference
     * @param  array<string, mixed>  $chosen
     * @return list<Fact>
     */
    private function extensionOfTime(Project $project, Collection $reference, array $chosen, CarbonImmutable $today): array
    {
        $stipulated = $reference->filter(fn (ContractTerm $term) => $term->code === 'stipulated_completion' && $term->value_date !== null)->values();

        if ($stipulated->isEmpty()) {
            throw ValidationException::withMessages(['type' => __('No stipulated completion date is confirmed under Contract terms for today. Enter and confirm it there first: the app does not know it.')]);
        }

        // Two confirmed completion dates for one day would leave the app to choose between them.
        if ($stipulated->count() > 1) {
            throw ValidationException::withMessages(['type' => __('More than one confirmed stipulated completion date covers today. Correct them under Contract terms first.')]);
        }

        $stipulated = $stipulated->first();
        $facts = [$this->fact('stipulated_completion', Fact::DATE, $stipulated->value_date->toDateString(), FactSource::record('contract_term', $stipulated->id, 'value_date'))];
        $base = HindranceMath::day($stipulated->value_date);

        $extension = $reference
            ->filter(fn (ContractTerm $term) => $term->code === 'extension_granted' && $term->value_date !== null)
            ->sortBy(fn (ContractTerm $term) => [$term->value_date->toDateString(), -$term->id])
            ->last();

        if ($extension !== null) {
            $facts[] = $this->fact('extension_granted', Fact::DATE, $extension->value_date->toDateString(), FactSource::record('contract_term', $extension->id, 'value_date'));
            $granted = HindranceMath::day($extension->value_date);
            $base = $granted->gt($base) ? $granted : $base;
        }

        $entries = $this->cited($project, SiteLetter::EOT_REQUEST, $chosen, $today);
        array_push($facts, ...$this->entryFacts($entries, $today));

        $total = $this->register->total($entries, $today)['days'];
        $facts[] = $this->fact('total_days', Fact::DAYS, $total, FactSource::computed('total_days'));

        // The latest of the stipulated date and the extensions granted, plus the days of the hindrances, unless the person typed another date.
        $facts[] = filled($chosen['proposed_completion'] ?? null)
            ? $this->typed('proposed_completion', Fact::DATE, (string) $chosen['proposed_completion'])[0]
            : $this->fact('proposed_completion', Fact::DATE, HindranceMath::plusDays($base, $total)->toDateString(), FactSource::computed('proposed_completion'));

        return [...$facts, ...$this->clauses($project, $entries, 'eot_notice')];
    }

    /**
     * @param  array<string, mixed>  $chosen
     * @return list<Fact>
     */
    private function delay(Project $project, array $chosen, CarbonImmutable $today): array
    {
        $entries = $this->cited($project, SiteLetter::DELAY_NOTICE, $chosen, $today);

        return [...$this->entryFacts($entries, $today), ...$this->clauses($project, $entries, 'delay_notice')];
    }

    /**
     * @param  array<string, mixed>  $chosen
     * @return list<Fact>
     */
    private function payment(Project $project, array $chosen, CarbonImmutable $today): array
    {
        if (! $this->billsAreKept(Organization::query()->findOrFail($project->organization_id))) {
            throw ValidationException::withMessages(['type' => __('A payment follow-up is made from client bills and the receipts recorded on them, which your plan does not keep.')]);
        }

        $ids = $this->ids($chosen['bill_ids'] ?? []);
        $unpaid = $this->unpaidBills($project);

        if ($ids === [] || array_diff($ids, $unpaid->keys()->all()) !== []) {
            throw ValidationException::withMessages(['bill_ids' => __('One of the chosen bills is not an approved client bill of this project with an amount outstanding any more. Choose the bills once more.')]);
        }

        sort($ids);
        $facts = [];
        $total = BigDecimal::zero();

        foreach ($ids as $at => $id) {
            $row = $unpaid->get($id);
            $bill = $row['bill'];
            $n = $at + 1;
            $date = self::billDate($bill);
            $from = fn (string $field) => FactSource::record('ra_bill', $bill->id, $field);

            $facts[] = $this->fact("bill_{$n}_number", Fact::REFERENCE, $bill->number, $from('number'));
            $facts[] = $this->fact("bill_{$n}_date", Fact::DATE, $date->toDateString(), $from($bill->approved_at !== null ? 'approved_at' : 'period_to'));
            $facts[] = $this->fact("bill_{$n}_receivable", Fact::MONEY, $row['receivable'], $from('receivable'));
            $facts[] = $this->fact("bill_{$n}_received", Fact::MONEY, $row['received'], $from('received'));

            // Shown only when a receipt carries it, so receivable less received less this is what is outstanding.
            if (! Dec::of($row['tds'])->isZero()) {
                $facts[] = $this->fact("bill_{$n}_tds", Fact::MONEY, $row['tds'], $from('tds'));
            }

            $facts[] = $this->fact("bill_{$n}_outstanding", Fact::MONEY, $row['outstanding'], $from('outstanding'));

            if (! $date->gt($today)) {
                $facts[] = $this->fact("bill_{$n}_days", Fact::DAYS, HindranceMath::daysUntil($today, $date), FactSource::computed("bill_{$n}_days"));
            }

            $total = $total->plus(Dec::of($row['outstanding']));
        }

        $facts[] = $this->fact('total_outstanding', Fact::MONEY, Dec::money($total), FactSource::computed('total_outstanding'));

        return $facts;
    }

    /**
     * The chosen entries, oldest start first. Each must still be one the letter may cite.
     *
     * @param  array<string, mixed>  $chosen
     * @return Collection<int, Hindrance>
     *
     * @throws ValidationException
     */
    private function cited(Project $project, string $type, array $chosen, CarbonImmutable $today): Collection
    {
        $ids = $this->ids($chosen['hindrance_ids'] ?? []);
        $entries = $this->citable($project, $type, $today)->whereIn('id', $ids)->values();

        if ($ids === [] || $entries->count() !== count($ids)) {
            throw ValidationException::withMessages(['hindrance_ids' => $type === SiteLetter::DELAY_NOTICE
                ? __('A notice of delay cites confirmed entries that are still going on. One of the chosen entries has ended, was dismissed or was corrected since. Choose the entries once more.')
                : __('One of the chosen entries is not a confirmed entry that counts any more: it was dismissed, removed or corrected since. Choose the entries once more.')]);
        }

        return $entries;
    }

    /**
     * @param  Collection<int, Hindrance>  $entries
     * @return list<Fact>
     */
    private function entryFacts(Collection $entries, CarbonImmutable $today): array
    {
        $facts = [];

        foreach ($entries as $at => $entry) {
            $n = $at + 1;
            $from = fn (string $field) => FactSource::record('hindrance', $entry->id, $field);

            // Not printed as a figure: the letter names the category in words. On the sheet, so a change of it is noticed.
            $facts[] = $this->fact("h{$n}_category", Fact::TEXT, $entry->category, $from('category'));
            $facts[] = $this->fact("h{$n}_what", Fact::TEXT, $entry->description, $from('description'));
            $facts[] = $this->fact("h{$n}_start", Fact::DATE, $entry->started_on->toDateString(), $from('started_on'));

            if ($entry->ended_on !== null) {
                $facts[] = $this->fact("h{$n}_end", Fact::DATE, $entry->ended_on->toDateString(), $from('ended_on'));
            }

            $facts[] = $this->fact("h{$n}_days", Fact::DAYS, HindranceMath::daysOf($entry->started_on, $entry->ended_on, $today), FactSource::computed("h{$n}_days"));

            // The dates of the reports the entry rests on, as the register copied them when they were cited.
            $dates = array_values(array_unique($entry->sources->map(fn ($source) => $source->report_date->toDateString())->all()));
            sort($dates);
            $reports = fn (string $field) => FactSource::record('hindrance_source', $entry->id, $field);

            if (count($dates) > 1) {
                $facts[] = $this->fact("h{$n}_report_days", Fact::COUNT, count($dates), $reports('report_days'));
                $facts[] = $this->fact("h{$n}_first_report", Fact::DATE, $dates[0], $reports('first_report'));
                $facts[] = $this->fact("h{$n}_last_report", Fact::DATE, $dates[count($dates) - 1], $reports('last_report'));
            } elseif ($dates !== []) {
                $facts[] = $this->fact("h{$n}_first_report", Fact::DATE, $dates[0], $reports('first_report'));
            }
        }

        return $facts;
    }

    /**
     * What the person typed as the clause or page of each notice period of this kind that applies
     * to the cited entries: the confirmed periods of the client contract in force on the day an
     * entry started, as the register reads them. The app adds nothing to these words.
     *
     * @param  Collection<int, Hindrance>  $entries
     * @return list<Fact>
     */
    private function clauses(Project $project, Collection $entries, string $notice): array
    {
        $terms = [];
        $inForce = [];
        $periods = $entries->isEmpty() ? collect() : $this->terms->confirmed($project, null, ['notice']);

        foreach ($entries as $entry) {
            $day = $entry->started_on->toDateString();
            $inForce[$day] ??= ContractTerms::inForce($periods, $entry->started_on);

            foreach ($inForce[$day]->where('code', $notice) as $term) {
                $terms[$term->id] = $term;
            }
        }

        ksort($terms);
        $facts = [];
        $seen = [];

        foreach ($terms as $term) {
            $words = trim((string) $term->source_ref);

            if ($words === '' || isset($seen[$words])) {
                continue;
            }

            $seen[$words] = true;
            $facts[] = $this->fact('clause_'.count($seen), Fact::REFERENCE, $words, FactSource::record('contract_term', $term->id, 'source_ref'));
        }

        return $facts;
    }

    /** The one confirmed term of this code, or null when there is none or more than one. */
    private function single(Collection $terms, string $code): ?ContractTerm
    {
        $found = $terms->where('code', $code)->values();

        return $found->count() === 1 ? $found->first() : null;
    }

    /** @return list<Fact> One fact for something the person typed on the letter, none when it was left empty. */
    private function typed(string $key, string $kind, ?string $value): array
    {
        return filled($value)
            ? [new Fact($key, $kind, trim((string) $value), LetterLayout::englishLabel($key, entered: true), FactSource::entered($key))]
            : [];
    }

    private function fact(string $key, string $kind, mixed $value, FactSource $source): Fact
    {
        // Words a person typed elsewhere. A letter cannot state a name or a description that is not there.
        if (in_array($kind, [Fact::TEXT, Fact::REFERENCE], true) && (! is_string($value) || trim($value) === '' || ! mb_check_encoding($value, 'UTF-8'))) {
            throw ValidationException::withMessages(['facts' => __('Something this letter would state is empty in your records or cannot be read as text. Check the description of each chosen entry and the name of the project first.')]);
        }

        return new Fact($key, $kind, $value, LetterLayout::englishLabel($key), $source);
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $ids): array
    {
        return array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));
    }
}
