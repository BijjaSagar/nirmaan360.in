<?php

namespace App\Services\Wording;

use InvalidArgumentException;

/** Where a fact was read from: the kind of record, its id and the field. */
final readonly class FactSource
{
    /** A figure the code worked out from several records, such as a total of days. */
    public const COMPUTED = 'computed';

    /** A figure a person typed on the document itself, shown as "entered by you". */
    public const ENTERED = 'entered';

    public function __construct(public string $type, public int|string|null $id = null, public ?string $field = null)
    {
        if (trim($type) === '') {
            throw new InvalidArgumentException('A fact source needs a type.');
        }
    }

    public static function record(string $type, int|string $id, string $field): self
    {
        return new self($type, $id, $field);
    }

    public static function computed(string $field): self
    {
        return new self(self::COMPUTED, null, $field);
    }

    public static function entered(string $field): self
    {
        return new self(self::ENTERED, null, $field);
    }

    /** @return array{type: string, id: ?string, field: ?string} The id is a string so the same record hashes the same on every database. */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id === null ? null : (string) $this->id, 'field' => $this->field];
    }

    /** @param  array{type?: mixed, id?: mixed, field?: mixed}  $source */
    public static function fromArray(array $source): self
    {
        $id = $source['id'] ?? null;

        return new self(
            (string) ($source['type'] ?? ''),
            $id === null ? null : (string) $id,
            isset($source['field']) ? (string) $source['field'] : null,
        );
    }
}
