<?php

namespace App\Services\Notify;

/**
 * Whether words written by a person, in any of the app's languages, carry a rupee figure.
 *
 * Broader than the push channel's own guard, because an announcement is free text, and it errs
 * towards finding money: a wrong match only keeps a message from people without money.view.
 *
 * - A rupee sign or a word for rupees anywhere is money, with or without a number, because the
 *   amount can be written in words: "₹ (five thousand)", "Rupees five thousand only",
 *   "पाँच हजार रुपये", "500 rupaya".
 * - Rs, INR, रु. and रू, and lakh, lac, crore, cr, लाख, कोटी, करोड and लक्ष, count beside a number,
 *   in figures of any script or in words ("Rs. five thousand", "रु. पाच हजार"), with a scale word
 *   ("5 thousand Rs", "५ हजार रु.") or a little punctuation ("Amount (Rs): 5000") between them.
 * - So does an amount ending in /-.
 *
 * Every mark is a whole word, so सुरू, शुरू, गुरु, रूम or रुंद beside a number are not money.
 * Invisible characters, such as a zero-width space, are removed first, and Devanagari is put in
 * one form so करोड़ matches however its last letter was typed.
 */
final class MoneyMention
{
    /** Not a letter or a vowel sign on this side: the edge of a word in any script. */
    private const EDGE_BEFORE = '(?<![\p{L}\p{M}])';

    private const EDGE_AFTER = '(?![\p{L}\p{M}])';

    /** Always money, alone. */
    private const RUPEES = '(?:₹|₨|'.self::EDGE_BEFORE.'(?:rupees?|rupaye|rupaiye|rupaya|rupaiya|rupaiyaa|rupaiyya|rupay|rupai|rupiya|rupiye|rupye|rupya|rupyaa)'.self::EDGE_AFTER
        .'|'.self::EDGE_BEFORE.'(?:रुपय|रुपए|रूपय|रूपए|रुपै|रुप्य))';

    /** Money beside a number. */
    private const MARKS = '(?:'.self::EDGE_BEFORE.'(?:rs|inr|lakhs?|lacs?|crores?|crs?)'.self::EDGE_AFTER.'\.?'
        .'|'.self::EDGE_BEFORE.'(?:रु|रू)'.self::EDGE_AFTER.'[.०॰]?'
        .'|'.self::EDGE_BEFORE.'(?:लाख|कोटी|करोड|लक्ष))';

    /** A number: figures in any script, or the words an amount is written in. */
    private const NUMBER = '(?:\p{Nd}|'.self::EDGE_BEFORE.'(?:one|two|three|four|five|six|seven|eight|nine|ten|twenty|fifty|hundred|thousand|lakhs?|lacs?|crores?)'.self::EDGE_AFTER
        .'|'.self::EDGE_BEFORE.'(?:एक|दो|दोन|तीन|चार|पाच|पाँच|पांच|सहा|छह|सात|आठ|नऊ|नौ|दहा|दस|वीस|बीस|पन्नास|पचास|शंभर|सौ|हजार|हज़ार|लाख|कोटी|करोड)'.self::EDGE_AFTER.')';

    /** What may stand between a mark and its number: spaces, a little punctuation, a scale word. */
    private const BETWEEN = '(?:[^\p{L}\p{M}\p{Nd}]{0,6}(?:'.self::EDGE_BEFORE.'(?:thousand|hundred|hazaa?r|k)'.self::EDGE_AFTER.'|हजार|हज़ार|सौ|शे)?[^\p{L}\p{M}\p{Nd}]{0,6})';

    public static function in(string $text): bool
    {
        $plain = self::plain($text);

        return (bool) preg_match('/'.self::RUPEES.'/iu', $plain)
            || (bool) preg_match('/'.self::MARKS.self::BETWEEN.self::NUMBER.'|'.self::NUMBER.self::BETWEEN.self::MARKS.'|\p{Nd}\s*\/-/iu', $plain);
    }

    private static function plain(string $text): string
    {
        // Format characters (zero-width space, joiners, direction marks) show nothing but would split a match.
        $plain = (string) preg_replace('/\p{Cf}/u', '', $text);

        // ड़ and other nukta letters have a one-letter and a two-letter form. Use the two-letter one.
        return strtr($plain, ["\u{095C}" => "\u{0921}\u{093C}", "\u{095D}" => "\u{0922}\u{093C}", "\u{0958}" => "\u{0915}\u{093C}", "\u{095B}" => "\u{091C}\u{093C}"]);
    }
}
