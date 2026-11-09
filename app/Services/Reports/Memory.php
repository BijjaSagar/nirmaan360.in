<?php

namespace App\Services\Reports;

/**
 * Big exports use more memory than a 128 MB limit allows: dompdf needs about 30 KB for every table
 * cell and PhpSpreadsheet about 1 KB. The exports are capped by cell count to fit within 256 MB,
 * and this raises the limit that far when it is lower.
 */
final class Memory
{
    private const WANTED = 256 * 1024 * 1024;

    public static function ensure(): void
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '-1') {
            return;
        }

        $bytes = (int) $limit * match (strtoupper(substr($limit, -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };

        if ($bytes < self::WANTED) {
            @ini_set('memory_limit', (string) self::WANTED);
        }
    }
}
