<?php

namespace App\Services\Crm;

use App\Models\CrmCustomField;
use App\Support\Dec;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Company-defined fields. Definitions live in crm_custom_fields and values in each record's custom_fields JSON. */
class CustomFieldService
{
    /** @return Collection<int,CrmCustomField> */
    public function definitions(int $organizationId, string $entity, bool $onlyActive = true): Collection
    {
        return CrmCustomField::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('entity', $entity)
            ->when($onlyActive, fn ($query) => $query->where('active', true))
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /**
     * Validation rules for the form's custom[key] inputs.
     *
     * @return array<string,list<mixed>>
     */
    public function rules(int $organizationId, string $entity): array
    {
        $rules = ['custom' => ['nullable', 'array']];

        foreach ($this->definitions($organizationId, $entity) as $field) {
            $rules['custom.'.$field->key] = [
                $field->required ? 'required' : 'nullable',
                ...match ($field->type) {
                    'number' => ['numeric', 'between:-9999999999999,9999999999999'],
                    'date' => ['date'],
                    'select' => [Rule::in($field->options ?? [])],
                    default => ['string', 'max:255'],
                },
            ];
        }

        return $rules;
    }

    /** Field labels for error messages, so a failure reads "Site area" rather than "custom.site_area". */
    public function attributes(int $organizationId, string $entity): array
    {
        return $this->definitions($organizationId, $entity)->mapWithKeys(fn ($field) => ['custom.'.$field->key => $field->label])->all();
    }

    /**
     * The values to store: only keys the company still defines, blanks dropped.
     * Values for fields that were switched off are kept as they are.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>|null  $existing
     * @return array<string,string>
     */
    public function clean(int $organizationId, string $entity, array $input, ?array $existing = null): array
    {
        $values = $existing ?? [];

        foreach ($this->definitions($organizationId, $entity) as $field) {
            $raw = $input[$field->key] ?? null;
            $raw = is_string($raw) ? trim($raw) : $raw;

            if ($raw === null || $raw === '') {
                unset($values[$field->key]);

                continue;
            }

            $values[$field->key] = $field->type === 'number' ? $this->normaliseNumber($raw) : (string) $raw;
        }

        return $values;
    }

    /** Add a field. The key comes from the label and stays fixed so saved values keep their place. */
    public function create(int $organizationId, array $data): CrmCustomField
    {
        $options = $data['type'] === 'select' ? $this->parseOptions($data['options'] ?? '') : null;

        if ($data['type'] === 'select' && $options === []) {
            throw ValidationException::withMessages(['options' => __('Add at least one choice, separated by commas.')]);
        }

        $sort = (int) CrmCustomField::withoutGlobalScopes()->where('organization_id', $organizationId)->where('entity', $data['entity'])->max('sort');

        return CrmCustomField::create([
            'organization_id' => $organizationId,
            'entity' => $data['entity'],
            'key' => $this->uniqueKey($organizationId, $data['entity'], $data['label']),
            'label' => $data['label'],
            'type' => $data['type'],
            'options' => $options,
            'required' => (bool) ($data['required'] ?? false),
            'sort' => $sort + 1,
        ]);
    }

    /** Change the label, choices, whether it is required, or switch it off. The key and type stay. */
    public function update(CrmCustomField $field, array $data): CrmCustomField
    {
        $options = $field->type === 'select' && isset($data['options']) ? $this->parseOptions($data['options']) : $field->options;

        if ($field->type === 'select' && $options === []) {
            throw ValidationException::withMessages(['options' => __('Add at least one choice, separated by commas.')]);
        }

        $field->update([
            'label' => $data['label'] ?? $field->label,
            'options' => $options,
            'required' => (bool) ($data['required'] ?? false),
            'active' => (bool) ($data['active'] ?? false),
        ]);

        return $field;
    }

    /** @return list<string> */
    public function parseOptions(string $text): array
    {
        return collect(preg_split('/[,\n]/', $text) ?: [])
            ->map(fn ($option) => trim($option))
            ->filter()
            ->unique()
            ->values()
            ->take(30)
            ->all();
    }

    private function normaliseNumber(mixed $value): string
    {
        try {
            $number = (string) Dec::of((string) $value);
        } catch (MathException) {
            return (string) $value;
        }

        // 12500.50 is kept as 12500.5, and 7.000 as 7.
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }

    private function uniqueKey(int $organizationId, string $entity, string $label): string
    {
        $base = Str::limit(Str::slug($label, '_'), 30, '') ?: 'field';
        $taken = CrmCustomField::withoutGlobalScopes()->where('organization_id', $organizationId)->where('entity', $entity)->pluck('key')->all();
        $key = $base;

        for ($number = 2; in_array($key, $taken, true); $number++) {
            $key = $base.'_'.$number;
        }

        return $key;
    }
}
