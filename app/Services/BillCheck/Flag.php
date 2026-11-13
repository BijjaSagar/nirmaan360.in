<?php

namespace App\Services\BillCheck;

/**
 * One finding of the pre-check: which check found it, on which row, how serious it is, and the figures
 * it found. It holds no sentence. FlagText turns it into words for the person reading it.
 *
 * The key stays the same from one run to the next for the same finding on the same row, so an
 * acknowledgement can be matched to it. The hash changes as soon as the level or any figure does.
 */
final class Flag
{
    /** Something on the bill does not add up, or contradicts a confirmed term. */
    public const BLOCKING = 'blocking';

    /** Something a person should look at before the bill goes out. */
    public const TICK = 'tick';

    /** Worth knowing. Needs no acknowledgement. */
    public const NOTE = 'note';

    public const LEVELS = [self::BLOCKING, self::TICK, self::NOTE];

    /** @param  array<string, string|int|null>  $params  Raw values: money and quantities as decimal strings, dates as Y-m-d. */
    public function __construct(
        public readonly string $key,
        public readonly string $code,
        public readonly string $level,
        public readonly array $params = [],
    ) {}

    /** Blocking and to-tick findings each need an acknowledgement with a remark. */
    public function needsAck(): bool
    {
        return $this->level !== self::NOTE;
    }

    /** The same for the same finding with the same figures, whatever order the figures were given in. */
    public function hash(): string
    {
        return hash('sha256', (string) json_encode($this->canonical(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array{0: string, 1: string, 2: array<string, ?string>} Key, level and the figures in a fixed order, all as text. */
    public function canonical(): array
    {
        $params = array_map(fn ($value) => $value === null ? null : (string) $value, $this->params);
        ksort($params, SORT_STRING);

        return [$this->key, $this->level, $params];
    }

    /** @return array{key: string, code: string, level: string, params: array<string, mixed>, hash: string} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'code' => $this->code, 'level' => $this->level, 'params' => $this->params, 'hash' => $this->hash()];
    }

    /** A finding as it was stored with a check. */
    public static function fromArray(array $stored): self
    {
        return new self(
            (string) ($stored['key'] ?? ''),
            (string) ($stored['code'] ?? ''),
            in_array($stored['level'] ?? null, self::LEVELS, true) ? $stored['level'] : self::TICK,
            is_array($stored['params'] ?? null) ? $stored['params'] : [],
        );
    }
}
