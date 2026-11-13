<?php

namespace App\Services\ContractTerms;

use App\Models\ContractTerm;
use App\Support\Money;

/**
 * A term in words, in the reader's language, for any screen that lists one. It repeats what was typed
 * and adds nothing: no figure is worked out here. Rupee amounts are left out for a reader who may not
 * see money, and for a run with nobody signed in.
 */
final class TermText
{
    /** The name typed for the term, or the code's own name in the reader's language. */
    public function name(ContractTerm $term): string
    {
        $standard = $this->option("codes.{$term->kind}", $term->code);

        return $standard !== null && ($term->label === $standard || $term->label === '') ? __($standard) : $term->label;
    }

    public function kind(ContractTerm $term): string
    {
        return __($this->option('kinds', $term->kind) ?? $term->kind);
    }

    /** What the term says, in one short line. */
    public function value(ContractTerm $term): string
    {
        return match ($term->kind) {
            'deduction' => match ($term->method) {
                'percent' => $this->percent($term->pct),
                'fixed_per_bill' => Money::visible() ? __(':amount on every bill', ['amount' => inr($term->amount)]) : __('A fixed amount on every bill'),
                default => __('Worked out on each bill'),
            },
            'excess_quantity' => __(':pct above the BOQ quantity', ['pct' => $this->percent($term->pct)]),
            'notice', 'payment_period' => $term->days === 1 ? __('1 day') : __(':days days', ['days' => $term->days]),
            'reference' => $term->value_date?->format('d M Y') ?? (string) $term->value_text,
            default => '',
        };
    }

    /**
     * The rest of what was typed, one short line each.
     *
     * @return list<string>
     */
    public function notes(ContractTerm $term): array
    {
        $notes = [];

        if ($term->base !== null) {
            $notes[] = __('Worked out on: :base', ['base' => __($this->option('bases', $term->base) ?? $term->base)]);
        }

        if ($term->cap_amount !== null && Money::visible()) {
            $notes[] = __('Not more than :amount in all', ['amount' => inr($term->cap_amount)]);
        }

        if ($term->nature !== null) {
            $notes[] = __($this->option('natures', $term->nature) ?? $term->nature);
        }

        if ($term->counted_from !== null) {
            $notes[] = __('Counted from: :start', ['start' => __($this->option("counted_from.{$term->kind}", $term->counted_from) ?? $term->counted_from)]);
        }

        if ($term->combine !== null) {
            $notes[] = __('Group :group: :choice', ['group' => $term->combine_group, 'choice' => __($this->option('combine', $term->combine) ?? $term->combine)]);
        }

        return $notes;
    }

    /** The days the term covers. */
    public function period(ContractTerm $term): string
    {
        $from = $term->effective_from->format('d M Y');

        return $term->effective_to === null
            ? __('From :from', ['from' => $from])
            : __(':from to :to', ['from' => $from, 'to' => $term->effective_to->format('d M Y')]);
    }

    /** A percentage as typed, without the zeros the column pads it with. */
    public function percent(mixed $pct): string
    {
        $text = (string) $pct;

        return (str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text).'%';
    }

    /** The name of one choice from the module's lists, or null when the list does not have it. */
    private function option(string $list, ?string $key): ?string
    {
        $options = config("modules.contract_terms.{$list}");

        return is_array($options) && $key !== null && is_string($options[$key] ?? null) ? $options[$key] : null;
    }
}
