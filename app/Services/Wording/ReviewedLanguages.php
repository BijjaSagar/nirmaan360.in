<?php

namespace App\Services\Wording;

use InvalidArgumentException;

/**
 * The languages a document for a client or another party may be prepared in, when it is put together
 * from fixed sentences the engineers wrote: a site letter, a quotation's cover note, a weekly update.
 *
 * Their Hindi and Marathi wait for a native reader's review. On the live site such a document is offered
 * in English and in the languages config('wording.reviewed_languages') lists as reviewed for its kind.
 * Everywhere else, staging included, every language is offered, so that the wording can be read before
 * it is released. A document already prepared keeps its language, and so does a new one made from it.
 *
 * Every module that writes such a document asks here, for the list on its form and for the rule that
 * checks what the form sent. Screens a person of the company reads for themselves (the register, the
 * bill pre-check, the rate helper) follow the person's own language and are not held.
 */
final class ReviewedLanguages
{
    /** Letters from the hindrance register and from unpaid client bills. */
    public const SITE_LETTERS = 'site_letters';

    /** The home-owner pack: a quotation's cover note and the weekly update. */
    public const OWNER_PACK = 'owner_pack';

    public const KINDS = [self::SITE_LETTERS, self::OWNER_PACK];

    /**
     * The languages of $all a new document of this kind may be prepared in, in the order given.
     *
     * @param  list<string>  $all  The languages the module can write, English among them.
     * @return list<string>
     */
    public static function offered(string $kind, array $all = FactFormat::LOCALES): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("'{$kind}' is not a kind of document whose languages are reviewed.");
        }

        if (! app()->isProduction()) {
            return array_values($all);
        }

        $reviewed = (array) config("wording.reviewed_languages.{$kind}", []);

        return array_values(array_filter($all, fn (string $code) => $code === 'en' || in_array($code, $reviewed, true)));
    }

    /**
     * The languages a document of this kind may be given now. A draft keeps the language it already
     * has, even one that is no longer offered, so that changing anything else on it never fails.
     *
     * @param  list<string>  $all
     * @return list<string>
     */
    public static function allowed(string $kind, array $all = FactFormat::LOCALES, ?string $current = null): array
    {
        $offered = self::offered($kind, $all);

        return $current !== null && in_array($current, $all, true) && ! in_array($current, $offered, true)
            ? [...$offered, $current]
            : $offered;
    }

    /** Whether every language the module writes is offered, so a page may say that it writes them all. */
    public static function allOffered(string $kind, array $all = FactFormat::LOCALES): bool
    {
        return self::offered($kind, $all) === array_values($all);
    }

    /** The language a new document starts in: the one asked for when it is offered, otherwise English. */
    public static function starting(string $kind, ?string $asked, array $all = FactFormat::LOCALES): string
    {
        return $asked !== null && in_array($asked, self::offered($kind, $all), true) ? $asked : 'en';
    }
}
