<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * One table row read from a scanned BOQ page, or added by hand, waiting for a person to check it.
 * A staging row, not part of the BOQ: it has no audit trail of its own, and what was checked and
 * confirmed is counted on the read when the BOQ is written (ai.read_confirmed).
 *
 * project_id is always the read's project, so a person limited to other projects never sees it.
 */
class BoqScanRow extends Model
{
    use BelongsToOrganization;

    public const ITEM = 'item';

    public const HEADING = 'heading';

    public const SUBTOTAL = 'subtotal';

    public const TOTAL = 'total';

    public const ADDON = 'addon';

    public const NOTE = 'note';

    public const EXCLUDED = 'excluded';

    /** What a row can be, as the reader or a person says. "excluded" is set only by leaving a row out. */
    public const KINDS = [self::ITEM, self::HEADING, self::SUBTOTAL, self::TOTAL, self::ADDON, self::NOTE];

    /** The rows that become BOQ lines. */
    public const IMPORTED = [self::ITEM, self::ADDON];

    /** The cells a person can type over. */
    public const FIELDS = ['section', 'code', 'description', 'unit', 'qty', 'rate', 'amount', 'percent'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'states' => 'array',
            'scripts' => 'array',
            'initial' => 'array',
            'typed' => 'array',
            'acks' => 'array',
            'overrides' => 'array',
            'qty' => 'decimal:3',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
            'percent' => 'decimal:4',
            'confirmed_at' => 'datetime',
        ];
    }

    public function isImported(): bool
    {
        return in_array($this->kind, self::IMPORTED, true);
    }

    /** A row the reader returned, as against one a person added by hand. */
    public function fromReader(): bool
    {
        return $this->kind_read !== null;
    }

    public function typedField(string $field): bool
    {
        return in_array($field, $this->typed ?? [], true);
    }

    /** The reader's remark on a cell: read, unclear or absent. Null when the reader gave none (a hand row, an amount with no partner). */
    public function stateOf(string $cell): ?string
    {
        $state = ($this->states ?? [])[$cell] ?? null;

        return is_string($state) ? $state : null;
    }
}
