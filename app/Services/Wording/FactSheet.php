<?php

namespace App\Services\Wording;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * The facts one document may state, in the order they are shown.
 *
 * Build it the same way every time (the same records in the same order), because the fingerprint
 * of the sheet a draft was made from is compared with the fingerprint of a sheet built again from
 * the records when the document is approved, finalised or shared.
 *
 * @implements IteratorAggregate<int, Fact>
 */
final class FactSheet implements Countable, IteratorAggregate
{
    /** Changes when the way the fingerprint is worked out changes, so old and new can never match by accident. */
    private const FINGERPRINT_VERSION = 'wording-facts-v1';

    /** @var array<string, Fact> */
    private array $facts = [];

    /** @param  iterable<Fact>  $facts */
    public function __construct(iterable $facts = [])
    {
        foreach ($facts as $fact) {
            if (! $fact instanceof Fact) {
                throw new InvalidArgumentException('A fact sheet holds facts only.');
            }

            if (isset($this->facts[$fact->key])) {
                throw new InvalidArgumentException("Fact '{$fact->key}' is on the sheet twice.");
            }

            $this->facts[$fact->key] = $fact;
        }
    }

    public static function of(Fact ...$facts): self
    {
        return new self($facts);
    }

    public function has(string $key): bool
    {
        return isset($this->facts[$key]);
    }

    public function find(string $key): ?Fact
    {
        return $this->facts[$key] ?? null;
    }

    public function get(string $key): Fact
    {
        return $this->facts[$key] ?? throw new WordingException("No fact '{$key}' is on the sheet.");
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->facts);
    }

    /** @return list<Fact> */
    public function all(): array
    {
        return array_values($this->facts);
    }

    public function count(): int
    {
        return count($this->facts);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->all());
    }

    /** Whether the sheet holds a fact of this kind, for example Fact::MONEY before showing it to someone without money.view. */
    public function hasKind(string $kind): bool
    {
        foreach ($this->facts as $fact) {
            if ($fact->kind === $kind) {
                return true;
            }
        }

        return false;
    }

    /**
     * sha256 over every fact's key, kind, value and source, in order. Labels are left out: they are
     * wording, not records. The kind is in, because it decides how the value is printed.
     */
    public function fingerprint(): string
    {
        $rows = array_map(
            fn (Fact $fact) => [$fact->key, $fact->kind, $fact->value, ...array_values($fact->source->toArray())],
            $this->all(),
        );

        return hash('sha256', json_encode([self::FINGERPRINT_VERSION, $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Whether a stored fingerprint is the fingerprint of this sheet. */
    public function matches(?string $fingerprint): bool
    {
        return is_string($fingerprint) && hash_equals($this->fingerprint(), $fingerprint);
    }

    /** @return list<array{key: string, kind: string, value: string, label: string, source: array{type: string, id: ?string, field: ?string}}> For a json column. */
    public function toArray(): array
    {
        return array_map(fn (Fact $fact) => $fact->toArray(), $this->all());
    }

    /** @param  iterable<array<string, mixed>>  $facts */
    public static function fromArray(iterable $facts): self
    {
        $sheet = [];

        foreach ($facts as $fact) {
            $sheet[] = Fact::fromArray((array) $fact);
        }

        return new self($sheet);
    }
}
