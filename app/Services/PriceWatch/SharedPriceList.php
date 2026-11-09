<?php

namespace App\Services\PriceWatch;

use App\Models\AuditLog;
use App\Models\PriceWatchSharedPrice;
use App\Models\User;
use App\Support\Dec;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The platform admin's shared list: one current price per material key, with its date and
 * source. Companies adopt it through PriceRecorder::pull(). Changes are audited, never silent.
 */
class SharedPriceList
{
    public function rules(): array
    {
        return [
            'key' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'unit' => ['required', 'string', 'max:20'],
            'rate' => ['required', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'priced_on' => ['required', 'date', 'before_or_equal:today'],
            'source' => ['nullable', 'string', 'max:160'],
        ];
    }

    /** Add a material to the list, or move an existing one's price on. The key is made from the name when blank, cut to what the column holds. */
    public function save(array $data, User $by): PriceWatchSharedPrice
    {
        $key = filled($data['key'] ?? null) ? $data['key'] : rtrim(mb_substr(Str::slug($data['name'], '_'), 0, 40), '_');

        if ($key === '') {
            throw ValidationException::withMessages(['key' => __('Give this material a key in English letters, such as cement or aac_blocks: its name cannot be turned into one.')]);
        }

        $row = PriceWatchSharedPrice::firstOrNew(['key' => $key]);
        $old = $row->exists ? $row->only(['name', 'unit', 'rate', 'priced_on', 'source']) : null;

        $row->fill([
            'name' => trim($data['name']),
            'unit' => trim($data['unit']),
            'rate' => Dec::money($data['rate']),
            'priced_on' => substr((string) $data['priced_on'], 0, 10),
            'source' => filled($data['source'] ?? null) ? trim($data['source']) : null,
            'updated_by' => $by->id,
        ])->save();

        AuditLog::record($old === null ? 'platform.shared_price_added' : 'platform.shared_price_changed', $row, $old, $row->only(['key', 'name', 'unit', 'rate', 'priced_on', 'source']));

        return $row;
    }

    public function delete(PriceWatchSharedPrice $row): void
    {
        $old = $row->only(['key', 'name', 'unit', 'rate', 'priced_on', 'source']);
        $row->delete();

        AuditLog::record('platform.shared_price_removed', $row, $old, null);
    }
}
