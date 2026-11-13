<?php

namespace Tests\Feature\DocumentReading\Support;

use App\Models\DocumentReadPage;

/** The stand-in under the "bill" figures: a supplier's bill read from a photo, on the interactive lane. */
class FakeBillKind extends FakeKind
{
    public function key(): string
    {
        return 'bill';
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
