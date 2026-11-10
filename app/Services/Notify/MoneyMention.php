<?php

namespace App\Services\Notify;

use Normalizer;

/**
 * Whether words written by a person, in any of the app's languages, carry a rupee figure.
 *
 * The writer is asked whether a message mentions money. This fills in "yes" on the form as they
 * type (the same patterns run in the browser, see patterns()) and overrides a "no" when it finds
 * money. It errs towards finding money, but a match keeps a notice from every worker without
 * money.view, so it must not fire on ordinary site notices either: every mark is a word of its own
 * and most need a number beside them.
 *
 * - A rupee sign or a word for rupees anywhere: "₹ (five thousand)", "Rupees five thousand only",
 *   "पाँच हजार रुपये", "500rupaye", "रुपिया".
 * - Rs, INR, रु or रू beside any word or number: "Rs. Fifteen Thousand Only", "रु. पंचवीस हजार", "500rs".
 * - lakh, crore, लाख, कोटी or करोड after a number or any other word: "forty lakh", "दीड लाख",
 *   "25lakh"; not "लाख-लाख बधाई". cr, lac and लक्ष only beside a number: not "लक्ष द्या" or "on CR".
 * - A number beside a money word: "Diwali bonus 5000", "5k bonus", "दिवाळी बोनस ५ हजार", "rate 380 per bag";
 *   not a date, a time or a size ("पगार ७ तारखेला", "दर 2 तासांनी", "10 फीट").
 * - An amount ending in /-, and anything the push channel's own guard (PushDispatcher::MONEY) finds.
 *
 * Spaces, a little punctuation, a scale word or a filler word ("approx", "लगभग") may stand between.
 * Text is put in one form first: full-width letters (Ｒｓ) become plain ones, invisible characters
 * go, and spaces are collapsed.
 */
final class MoneyMention
{
    private const EDGE_BEFORE = '(?<![\p{L}\p{M}])';

    private const EDGE_AFTER = '(?![\p{L}\p{M}])';

    private const GAP = '[^\p{L}\p{M}\p{Nd}]';

    /** Always money, alone. */
    private const RUPEES = '(?:₹|₨'
        .'|'.self::EDGE_BEFORE.'(?:rupees?|rupaye|rupaiye|rupaya|rupaiya|rupaiyaa|rupaiyya|rupay|rupai|rupiya|rupiye|rupye|rupya|rupyaa)'.self::EDGE_AFTER
        .'|'.self::EDGE_BEFORE.'(?:रु|रू)प[ियैइीएे्])';

    /** Short marks for rupees: money beside any word or number. */
    private const CURRENCY = '(?:'.self::EDGE_BEFORE.'(?:rs|inr)'.self::EDGE_AFTER.'\.?|'.self::EDGE_BEFORE.'(?:रु|रू)'.self::EDGE_AFTER.'[.०॰]?)';

    /** Scale words that mean nothing but an amount. लाख, कोटी and करोड take endings (लाखांचे). */
    private const SCALE = '(?:'.self::EDGE_BEFORE.'(?:lakhs?|crores?)'.self::EDGE_AFTER.'|'.self::EDGE_BEFORE.'(?:लाख|कोटी|करोड))';

    /** Scale words that are also other words (cr is Central Railway, लक्ष is attention): money only beside a number. */
    private const SHORT_SCALE = '(?:'.self::EDGE_BEFORE.'(?:crs?|lacs?)'.self::EDGE_AFTER.'\.?|'.self::EDGE_BEFORE.'लक्ष'.self::EDGE_AFTER.')';

    /** Words that make a number beside them an amount. फी and दर take no endings (फीट is feet, दरवाजा a door). */
    private const MONEY_WORDS = '(?:'.self::EDGE_BEFORE.'(?:bonus(?:es)?|salar(?:y|ies)|wages?|advances?|payments?|paid|rates?|budgets?|costs?|amounts?|fees?|price|charges?)'.self::EDGE_AFTER
        .'|'.self::EDGE_BEFORE.'(?:पगार|बोनस|मजुरी|मज़दूरी|मजदूरी|आगाऊ|अग्रिम|वेतन|भत्ता|भत्ते|किंमत|कीमत|बजेट|शुल्क)'
        .'|'.self::EDGE_BEFORE.'(?:फी|फीस|दर)'.self::EDGE_AFTER.')';

    /** A number written out, for amounts in words beside a mark. */
    private const NUMBER_WORDS = '(?:'.self::EDGE_BEFORE.'(?:one|two|three|four|five|six|seven|eight|nine|ten|fifteen|twenty|twenty-five|forty|fifty|hundred|thousand|ek|do|teen|char|paanch|das|bees|pachaas|sau|hazaa?r)'.self::EDGE_AFTER
        .'|'.self::EDGE_BEFORE.'(?:एक|दो|दोन|तीन|चार|पाच|पाँच|पांच|सहा|छह|सात|आठ|नऊ|नौ|दहा|दस|पंधरा|पंद्रह|वीस|बीस|पंचवीस|पच्चीस|पन्नास|पचास|शंभर|सौ|हजार|हज़ार|दीड|डेढ़|अडीच|ढाई)'.self::EDGE_AFTER.')';

    /** Dates, times and sizes: a number before one of these is not an amount. */
    private const NOT_MONEY_UNITS = '(?!'.self::GAP.'{0,3}(?:(?:th|st|nd|rd|days?|hours?|hrs?|mins?|minutes?|weeks?|months?|feet|ft|cum|sqm|sqft|metres?|meters?|floors?|am|pm|o.clock)'.self::EDGE_AFTER.'|तारीख|तारखे|तास|दिवस|दिन|घंटे|घंटा|मिनिट|मिनट|आठवड|हफ्त|महिन|महीन|फीट|फूट|मीटर|मजल|बजे|वाजता))';

    /** A number in figures, with a short scale after it: 5000, 5,000, 5k, 2.5L, ५ हजार. Not part of a word (7th). */
    private const AMOUNT = '\p{Nd}[\p{Nd},.]*(?:\s?(?:[kKlL]|हजार|हज़ार|thousand)'.self::EDGE_AFTER.'|(?![\p{L}\p{M}\p{Nd},.]))';

    /** Between a mark and its word or number: spaces, a little punctuation, a scale or filler word. */
    private const BETWEEN = '(?:'.self::GAP.'{0,6}(?:(?:thousand|hundred|hazaa?r|approx|about|around|only|nearly|roughly|of|is|हजार|हज़ार|सौ|शे|लगभग|अंदाजे|अंदाज़न|सुमारे|करीब|फक्त|सिर्फ|का|की|के|चा|ची|चे)'.self::EDGE_AFTER.self::GAP.'{0,6})?)';

    /**
     * The patterns, as regular expression sources that both PHP (PCRE) and the browser understand,
     * each used case-insensitively with Unicode on.
     *
     * @return list<string>
     */
    public static function patterns(): array
    {
        // A word that is not itself a scale word, before a scale word that is not said twice, so
        // "लाख-लाख बधाई" and "कोटी कोटी धन्यवाद" are not amounts.
        $notScale = '(?!(?:lakhs?|crores?|लाख|कोटी|करोड))';

        return [
            self::RUPEES,
            self::CURRENCY.self::BETWEEN.'[\p{L}\p{Nd}]',
            '[\p{L}\p{M}\p{Nd}]'.self::GAP.'{0,6}'.self::CURRENCY,
            '\p{Nd}'.self::GAP.'{0,6}'.self::SCALE,
            self::EDGE_BEFORE.$notScale.'[\p{L}\p{M}]+'.self::GAP.'{1,6}'.self::SCALE.'(?![\p{L}\p{M}]*'.self::GAP.'{1,3}(?:lakhs?|crores?|लाख|कोटी|करोड))',
            self::SCALE.self::BETWEEN.'\p{Nd}',
            '\p{Nd}'.self::GAP.'{0,6}'.self::SHORT_SCALE,
            self::NUMBER_WORDS.self::GAP.'{1,6}'.self::SHORT_SCALE,
            self::SHORT_SCALE.self::BETWEEN.'\p{Nd}',
            self::MONEY_WORDS.self::BETWEEN.self::AMOUNT.self::NOT_MONEY_UNITS,
            self::AMOUNT.self::NOT_MONEY_UNITS.self::BETWEEN.self::MONEY_WORDS,
            '\p{Nd}\s*\/-',
            // PushDispatcher::MONEY, so this is never narrower than the push channel's own guard.
            '(?:₹|(?<![\p{L}\p{M}\p{Nd}_])Rs\.?|(?<![\p{L}\p{M}\p{Nd}_])INR)\s*-?\d',
        ];
    }

    public static function in(string $text): bool
    {
        return self::evidence($text) !== null;
    }

    /** The words that looked like money, to show the writer why their "no" was not taken. Null when none. */
    public static function evidence(string $text): ?string
    {
        // The push channel's guard on the text as written, before invisible characters are removed.
        if (preg_match(PushDispatcher::MONEY, $text, $match)) {
            // The whole figure, for the writer to recognise.
            return preg_match('/(₹|\bRs\.?|\bINR)\s*-?\d[\d,.]*/iu', $text, $whole) ? trim($whole[0]) : trim($match[0]);
        }

        $plain = self::plain($text);

        foreach (self::patterns() as $pattern) {
            if ($plain !== '' && preg_match('/'.$pattern.'/iu', $plain, $match)) {
                return trim($match[0]);
            }
        }

        return null;
    }

    /**
     * Each field alone, and title with message as one, in English and in each language written,
     * where a missing translation reads as the English text, as the phone would show it. So a mark
     * in the title and its number in the message ("Cement rate", "380 per bag") are found.
     *
     * @param  array<string, array{title?: string, body?: string}>  $translations
     */
    public static function inAnnouncement(string $title, string $body, array $translations = []): bool
    {
        return self::evidenceInAnnouncement($title, $body, $translations) !== null;
    }

    /** @param  array<string, array{title?: string, body?: string}>  $translations */
    public static function evidenceInAnnouncement(string $title, string $body, array $translations = []): ?string
    {
        $texts = [$title, $body, $title."\n".$body];

        foreach ($translations as $text) {
            $localTitle = (string) ($text['title'] ?? '') ?: $title;
            $localBody = (string) ($text['body'] ?? '') ?: $body;
            array_push($texts, $localTitle, $localBody, $localTitle."\n".$localBody);
        }

        foreach ($texts as $text) {
            if (($found = self::evidence($text)) !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function plain(string $text): string
    {
        // Full-width and other compatibility forms (Ｒｓ, ＩＮＲ) become plain letters; ड़ and other nukta
        // letters come out in their two-letter form, however they were typed.
        $plain = class_exists(Normalizer::class) ? (string) (Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text) : $text;

        // Format characters (zero-width space, joiners, direction marks) show nothing but would split a match.
        $plain = (string) preg_replace('/\p{Cf}/u', '', $plain);

        return trim((string) preg_replace('/\s+/u', ' ', $plain));
    }
}
