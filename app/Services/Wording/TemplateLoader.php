<?php

namespace App\Services\Wording;

use Illuminate\Contracts\Translation\Translator;

/**
 * Gives every slot of a document its code text in English, Hindi or Marathi, and refuses a
 * template that could put a figure into a document by any way other than a declared token.
 *
 * A template written as one English string is looked up in the language files the module loads
 * (lang/modules/<module>/hi.json and mr.json), like any other text of the product. A template
 * given as an array carries its three languages in code.
 */
final class TemplateLoader
{
    /** A fixed template may name the rupee or the percentage beside the token that carries the figure. */
    private const ALLOWED_AROUND_TOKENS = [Violation::CURRENCY, Violation::PERCENT];

    public function __construct(private Translator $translator, private SlotGuard $guard) {}

    /**
     * @return array<string, string> Slot id => its template in that language.
     *
     * @throws WordingException When a template is missing, states an undeclared fact, drops a declared one, or carries a numeral.
     */
    public function load(DocumentType $type, string $locale): array
    {
        FactFormat::locale($locale);

        $templates = [];

        foreach ($type->slots as $slot) {
            $templates[$slot->id] = $this->checked($type, $slot, $this->text($type, $slot, $locale), $locale);
        }

        return $templates;
    }

    /** Throws unless every slot has a usable template in all three languages. For each feature's own test. */
    public function assertComplete(DocumentType $type): void
    {
        foreach (FactFormat::LOCALES as $locale) {
            $this->load($type, $locale);
        }
    }

    private function text(DocumentType $type, Slot $slot, string $locale): string
    {
        if (is_array($slot->template)) {
            return $slot->template[$locale];
        }

        if ($locale === 'en') {
            return $slot->template;
        }

        // Only tokens and punctuation: nothing to translate.
        if (preg_match('/\p{L}/u', (string) preg_replace(Slot::TOKEN_PATTERN, '', $slot->template)) !== 1) {
            return $slot->template;
        }

        $translated = $this->translator->get($slot->template, [], $locale, false);

        if (! is_string($translated) || trim($translated) === '' || $translated === $slot->template) {
            throw new WordingException("Document type '{$type->key}': slot '{$slot->id}' has no '{$locale}' template.");
        }

        return $translated;
    }

    private function checked(DocumentType $type, Slot $slot, string $template, string $locale): string
    {
        $where = "Document type '{$type->key}': the '{$locale}' template of slot '{$slot->id}'";

        if (! mb_check_encoding($template, 'UTF-8')) {
            throw new WordingException("{$where} is not valid text.");
        }

        $tokens = Slot::tokens($template);
        $outside = (string) preg_replace(Slot::TOKEN_PATTERN, ' ', $template);

        if (str_contains($outside, '{{') || str_contains($outside, '}}')) {
            throw new WordingException("{$where} has a token that is not written as {{name}}.");
        }

        if ($slot->isFree() && $tokens !== []) {
            throw new WordingException("{$where} carries a token. A free slot states no fact.");
        }

        if ($slot->isFixed() && (array_diff($tokens, array_keys($slot->facts)) !== [] || array_diff(array_keys($slot->facts), $tokens) !== [])) {
            throw new WordingException("{$where} must state exactly the facts the slot declares.");
        }

        // The words around the tokens must not state a figure of their own: a numeral, a month, "fifteen days".
        // No record stands behind such a figure. A free slot keeps every rule a model's text has to keep.
        foreach ($this->guard->check($outside, $locale) as $violation) {
            if ($slot->isFree() || ! in_array($violation->rule, self::ALLOWED_AROUND_TOKENS, true)) {
                throw new WordingException("{$where} states a figure in its own words (rule '{$violation->rule}'). Every figure must be a {{token}} filled from a fact.");
            }
        }

        return $template;
    }
}
