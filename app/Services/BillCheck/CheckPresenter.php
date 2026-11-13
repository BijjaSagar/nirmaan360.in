<?php

namespace App\Services\BillCheck;

use App\Models\BillCheck;
use App\Models\BillCheckAck;
use App\Models\ContractTerm;
use App\Models\RaBill;
use App\Models\User;
use App\Services\ContractTerms\TermText;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * The newest check of a bill, made ready for one reader: for the card on the bill page and for the
 * JSON route. Every rupee figure, contract percentage and word copied from a contract term passes
 * through here, and none of them reaches a reader without money.view. The JSON keeps each key and
 * carries null in its place.
 */
class CheckPresenter
{
    public function __construct(private BillChecks $checks, private FlagText $text, private TermText $terms) {}

    /**
     * What the card on the bill page shows, or null when the bill has no card: a bill that can no longer
     * be checked (submitted, approved or paid) and was never checked has nothing to say and nothing to
     * press, so the card and the company's switch inside it stay off that bill. A draft or returned bill,
     * which can still be checked, and any bill with a stored check have the card.
     *
     * @return array{check: ?array<string, mixed>, required: bool, can: array{run: bool, acknowledge: bool, set_policy: bool}}|null
     */
    public function card(RaBill $bill, ?User $viewer): ?array
    {
        $presented = $this->present($bill, $viewer);

        return $presented['check'] !== null || $bill->isEditable() ? $presented : null;
    }

    /**
     * @return array{check: ?array<string, mixed>, required: bool, can: array{run: bool, acknowledge: bool, set_policy: bool}}
     */
    public function present(RaBill $bill, ?User $viewer): array
    {
        $money = $viewer !== null && Money::visible($viewer);
        $check = $this->checks->latest($bill);
        $mayWork = $viewer !== null && $viewer->can('ra_bill.generate') && $bill->isEditable();

        return [
            'check' => $check === null ? null : $this->check($check, $money),
            'required' => $this->checks->isRequired($bill->organization_id),
            'can' => [
                'run' => $mayWork,
                'acknowledge' => $mayWork && $check !== null,
                'set_policy' => $viewer !== null && $viewer->can('settings.manage'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function check(BillCheck $check, bool $money): array
    {
        $acks = BillCheckAck::withoutGlobalScopes()->with(['user' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('bill_check_id', $check->id)->orderBy('id')->get()->keyBy('flag_key');

        $flags = collect($check->flags ?? [])->map(fn (array $stored) => $this->flag(Flag::fromArray($stored), $acks, $money))->values();
        $open = $flags->where('needs_ack', true)->whereNull('ack')->count();
        $runBy = $check->runBy()->withoutGlobalScopes()->first();

        return [
            'id' => $check->id,
            'ra_bill_id' => $check->ra_bill_id,
            'created_at' => $check->created_at?->toIso8601String(),
            'run_by' => $runBy === null ? null : ['id' => $runBy->id, 'name' => $runBy->name],
            'flags_hash' => $money ? $check->flags_hash : null,
            'summary' => [
                ...array_fill_keys(Flag::LEVELS, 0),
                ...array_intersect_key($check->summary ?? [], array_flip(Flag::LEVELS)),
                'to_acknowledge' => $open,
            ],
            'flags' => $flags->all(),
            'expected' => $this->sheet($check->expected, $money),
        ];
    }

    /**
     * @param  Collection<string, BillCheckAck>  $acks
     * @return array<string, mixed>
     */
    private function flag(Flag $flag, Collection $acks, bool $money): array
    {
        $ack = $acks->get($flag->key);

        return [
            'key' => $flag->key,
            'code' => $flag->code,
            'level' => $flag->level,
            'text' => $this->text->line($flag, $money),
            'params' => $this->text->params($flag, $money),
            'needs_ack' => $flag->needsAck(),
            // A remark is free text, and people write amounts in it. It is read with the figures or not at all.
            'ack' => $ack === null ? null : [
                'remark' => $money ? $ack->remark : null,
                'user' => $ack->user === null ? null : ['id' => $ack->user->id, 'name' => $ack->user->name],
                'created_at' => $ack->created_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * The sheet of deductions that are not on the bill. It is contract terms and rupees from top to
     * bottom, so a reader without money.view gets only how many lines it has.
     *
     * @param  array<string, mixed>|null  $sheet
     * @return array<string, mixed>|null
     */
    private function sheet(?array $sheet, bool $money): ?array
    {
        if ($sheet === null) {
            return null;
        }

        $lines = $sheet['lines'] ?? [];

        if (! $money) {
            return ['party' => $sheet['party'] ?? 'client', 'count' => count($lines), 'lines' => null, 'unpriced' => null, 'total' => null, 'net_payable' => null, 'expected_receipt' => null];
        }

        return [
            'party' => $sheet['party'] ?? 'client',
            'count' => count($lines),
            'lines' => array_map(fn (array $line) => $this->sheetLine($line), $lines),
            'unpriced' => (int) ($sheet['unpriced'] ?? 0),
            'total' => $sheet['total'] ?? null,
            'net_payable' => $sheet['net_payable'] ?? null,
            'expected_receipt' => $sheet['expected_receipt'] ?? null,
        ];
    }

    /**
     * One line of the sheet, with the words the contract terms page uses for the same term.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function sheetLine(array $line): array
    {
        // Never saved: only a way to print the stored line the way a term is printed everywhere else.
        $term = new ContractTerm;
        $term->forceFill([
            'kind' => 'deduction', 'code' => $line['code'] ?? 'other', 'label' => (string) ($line['label'] ?? ''),
            'method' => $line['method'] ?? null, 'pct' => $line['pct'] ?? null, 'amount' => $line['fixed_amount'] ?? null,
            'base' => $line['base'] ?? null, 'cap_amount' => $line['cap_amount'] ?? null, 'nature' => $line['nature'] ?? null,
            'effective_from' => $line['effective_from'] ?? null, 'effective_to' => $line['effective_to'] ?? null,
        ]);

        return [
            ...$line,
            'name' => $this->terms->name($term),
            'value' => $this->terms->value($term),
            'notes' => $this->terms->notes($term),
            'period' => $term->effective_from === null ? '' : $this->terms->period($term),
        ];
    }
}
