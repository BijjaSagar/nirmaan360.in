<?php

namespace App\Services\DocumentReading;

/**
 * What the code made of one printed number.
 *
 * "value" is a plain decimal string such as "100000.50", decimals as printed, and is null unless
 * "ok". "reason" says why not. "script" is the script of the printed digits and "hadComma" tells the
 * screen to mark a value whose reading depended on how its commas were placed.
 */
final readonly class PrintedNumberResult
{
    public function __construct(
        public bool $ok,
        public ?string $value,
        public ?string $reason,
        public string $script,
        public bool $hadComma,
    ) {}

    /** @return array{ok: bool, value: ?string, reason: ?string, script: string, had_comma: bool} */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'value' => $this->value, 'reason' => $this->reason, 'script' => $this->script, 'had_comma' => $this->hadComma];
    }
}
