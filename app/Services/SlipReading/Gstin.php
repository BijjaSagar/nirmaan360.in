<?php

namespace App\Services\SlipReading;

use App\Services\Finance\Gst;

/**
 * Checks a GSTIN read from a photo, by code.
 *
 * The check character follows the base-36 rule that is commonly published; it was not taken from a
 * GSTN document. So a failed check never blocks anything and is never a reason to read the photo
 * again: it only means the GSTIN is not used to find the supplier, and the person picks or types
 * the supplier instead.
 */
final class Gstin
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** Two digits, five letters, four digits, a letter, a letter or digit, "Z", a letter or digit. */
    private const SHAPE = '/^\d{2}[A-Z]{5}\d{4}[A-Z][A-Z0-9]Z[A-Z0-9]$/';

    /** The GSTIN as it is compared: upper case, without spaces, dots or hyphens. */
    public static function normalise(string $raw): string
    {
        return (string) preg_replace('/[\s\p{Z}.\-]+/u', '', mb_strtoupper(trim($raw)));
    }

    /**
     * What is wrong with a GSTIN, as codes: shape, state, pan, check. Empty when it passes.
     *
     * @param  ?string  $pan  The supplier's PAN when one is stored: characters 3 to 12 of the GSTIN must be it.
     * @return list<string>
     */
    public static function problems(string $gstin, ?string $pan = null): array
    {
        $gstin = self::normalise($gstin);

        if (preg_match(self::SHAPE, $gstin) !== 1) {
            return ['shape'];
        }

        $problems = [];

        // The state list may lack special codes, which is one more reason this is a warning only.
        if (Gst::codeFromGstin($gstin) === null) {
            $problems[] = 'state';
        }

        if (filled($pan) && substr($gstin, 2, 10) !== self::normalise((string) $pan)) {
            $problems[] = 'pan';
        }

        if ($gstin[14] !== self::checkCharacter(substr($gstin, 0, 14))) {
            $problems[] = 'check';
        }

        return $problems;
    }

    /** The fifteenth character for the first fourteen. */
    public static function checkCharacter(string $first14): string
    {
        $sum = 0;

        foreach (str_split(strtoupper($first14)) as $index => $character) {
            $value = strpos(self::ALPHABET, $character);
            $product = ($value === false ? 0 : $value) * ($index % 2 === 0 ? 1 : 2);
            $sum += intdiv($product, 36) + $product % 36;
        }

        return self::ALPHABET[(36 - $sum % 36) % 36];
    }
}
