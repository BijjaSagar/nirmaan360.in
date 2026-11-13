<?php

namespace Tests\Feature\DocumentReading\Support;

use App\Models\DocumentReadPage;

/** The stand-in under the "slip" figures: one short call on the interactive lane, watched on its own form. */
class FakeSlipKind extends FakeKind
{
    public function key(): string
    {
        return 'slip';
    }

    public function passes(): array
    {
        return ['main', 'recheck'];
    }

    public function nextPasses(DocumentReadPage $page): array
    {
        return [];
    }
}
