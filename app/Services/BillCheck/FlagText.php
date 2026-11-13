<?php

namespace App\Services\BillCheck;

use App\Services\ContractTerms\TermText;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * A finding in words, in the reader's language. The sentence is a fixed template from FlagCatalog and
 * the figures are placed into it here, by code. A reader who may not see money gets the sentence that
 * names no rupee figure, no contract percentage and no words from a contract term.
 */
final class FlagText
{
    public function __construct(private TermText $terms) {}

    /** The sentence for a finding. $money says whether the reader may see money. */
    public function line(Flag $flag, bool $money): string
    {
        $template = FlagCatalog::template($flag->code, $money);

        if ($template === null) {
            return __('A finding that this version of the app cannot put into words.');
        }

        $types = FlagCatalog::definition($flag->code)['params'];
        $shown = [];

        foreach ($types as $name => $type) {
            $shown[$name] = $this->shown($type, $flag->params[$name] ?? null);
        }

        return __($template, $shown);
    }

    /**
     * The figures of a finding for a JSON reader: every key kept, and the ones a reader without
     * money.view may not have set to null.
     *
     * @return array<string, mixed>
     */
    public function params(Flag $flag, bool $money): array
    {
        $params = $flag->params;

        if (! $money) {
            // A finding the catalogue no longer knows gives nothing away.
            $private = FlagCatalog::definition($flag->code) === null ? array_keys($params) : FlagCatalog::privateParams($flag->code);

            foreach ($private as $name) {
                if (array_key_exists($name, $params)) {
                    $params[$name] = null;
                }
            }
        }

        return $params;
    }

    /** One figure as it is printed in a sentence. */
    private function shown(string $type, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($type) {
            'money' => inr($value),
            'pct', 'ratio' => $this->terms->percent($value),
            'qty' => fmt_qty($value),
            'date' => $this->date((string) $value),
            'head' => __(FlagCatalog::HEADS[$value] ?? (string) $value),
            default => (string) $value,
        };
    }

    private function date(string $value): string
    {
        try {
            return CarbonImmutable::parse($value)->format('d M Y');
        } catch (Throwable) {
            return $value;
        }
    }
}
