<?php

namespace Tests\Feature\DocumentReading\Support;

/**
 * The real modules deploy their readers (the scanned BOQ, the challan and the bill, the tender). The shared
 * reader tests stand in for them under the same keys ("boq", "slip", "bill", "tender"), and two classes under
 * one key stop every reader, so a test that registers stand-ins, or wants no reader deployed, sets the real
 * ones aside first. The routes the real modules registered when the app booted stay; only the kinds go.
 */
trait SetsAsideShippedReaders
{
    protected function withoutShippedReaders(): void
    {
        foreach (array_keys(config('modules', [])) as $module) {
            if ($module !== 'reader_stand_ins') {
                config(["modules.{$module}.read_kinds" => []]);
            }
        }
    }
}
