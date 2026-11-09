<?php

namespace App\Services\Agents;

use App\Services\Agents\Tools\Tool;

/**
 * L0 advises, L1 saves drafts, L2 acts only after a person allows it.
 * L3 from the SRS is left out on purpose: no agent here may act unsupervised.
 */
final class Autonomy
{
    public const LEVELS = [
        'L0' => 'Advise only',
        'L1' => 'Save drafts for a person',
        'L2' => 'Act after a person approves',
    ];

    public static function rank(string $level): int
    {
        return (int) array_search($level, array_keys(self::LEVELS), true);
    }

    /** @return array<string,string> */
    public static function upTo(string $max): array
    {
        return array_slice(self::LEVELS, 0, self::rank($max) + 1, true);
    }

    public static function allows(string $autonomy, string $effect): bool
    {
        return match ($effect) {
            Tool::READ => true,
            Tool::DRAFT => self::rank($autonomy) >= self::rank('L1'),
            Tool::APPROVAL => self::rank($autonomy) >= self::rank('L2'),
            default => false,
        };
    }
}
