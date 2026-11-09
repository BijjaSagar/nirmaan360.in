<?php

namespace App\Services\Planning;

/** Where one activity stands, worked out from approved measurements. */
final readonly class ActivityProgress
{
    public const UNLINKED = 'unlinked';

    public const NOT_STARTED = 'not_started';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETE = 'complete';

    public const OVERDUE = 'overdue';

    public const LATE_START = 'late_start';

    public function __construct(
        public string $state,
        public ?string $percent = null,
        public ?string $expected = null,
        public string $value = '0.00',
        public string $earned = '0.00',
        public ?string $delayKind = null,
        public int $delayDays = 0,
        public ?string $actualStart = null,
        public ?string $actualFinish = null,
        public ?int $baselineSlip = null,
    ) {}

    public function isLinked(): bool
    {
        return $this->state !== self::UNLINKED;
    }

    public function isComplete(): bool
    {
        return $this->state === self::COMPLETE;
    }

    public function isDelayed(): bool
    {
        return $this->delayKind !== null;
    }
}
