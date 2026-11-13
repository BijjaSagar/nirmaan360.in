<?php

namespace App\Services\OwnerPack;

use App\Services\Wording\Rendered;
use App\Services\Wording\RenderedSlot;

/**
 * The fingerprint of the text a review page showed: its language and every sentence as written.
 *
 * The fact fingerprint covers the figures only. A colleague who saves the same draft in another
 * language, or with other sentences that carry no figure, leaves it unchanged, so issuing and
 * approving also compare this one: a person signs off only the text they read.
 */
final class ShownText
{
    public static function fingerprint(Rendered $document): string
    {
        return hash('sha256', json_encode([
            'type' => $document->type,
            'locale' => $document->locale,
            'slots' => array_map(fn (RenderedSlot $slot) => [$slot->id, $slot->text], $document->slots),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** Whether a form sent back the fingerprint of this text. */
    public static function matches(Rendered $document, mixed $sent): bool
    {
        return is_string($sent) && hash_equals(self::fingerprint($document), $sent);
    }
}
