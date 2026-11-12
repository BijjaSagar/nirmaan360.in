<?php

namespace Tests\Feature\DocumentReading\Support;

use App\Models\DocumentReadPage;

/** The same stand-in under the "tender" figures: runs of text pages that are split when an answer is cut off. */
class FakeTextKind extends FakeKind
{
    public function key(): string
    {
        return 'tender';
    }

    public function passes(): array
    {
        return ['text', 'image'];
    }

    public function nextPasses(DocumentReadPage $page): array
    {
        return [];
    }

    public function onCutOff(DocumentReadPage $page): string
    {
        return $page->pass === 'text' ? 'split_run' : 'fail';
    }
}
