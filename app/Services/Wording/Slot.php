<?php

namespace App\Services\Wording;

use InvalidArgumentException;

/**
 * One part of a document.
 *
 * A fixed slot is a sentence or block the code writes. A figure, a date or a name can stand nowhere
 * else: each is a {{token}} that the code fills from a fact.
 *
 * A free slot is figure-free prose around the fixed ones: an opening, a linking sentence, a close.
 * A model may write free slots and nothing else. Each has its own code text for when no model is used.
 */
final readonly class Slot
{
    public const FIXED = 'fixed';

    public const FREE = 'free';

    public const ID_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /** A token is {{name}} with nothing else between the braces. */
    public const TOKEN_PATTERN = '/\{\{([a-z][a-z0-9_]{0,63})\}\}/';

    /**
     * @param  string|array<string, string>  $template  English text, whose Hindi and Marathi come from the
     *                                                  module's language files, or the text in all three: ['en' => .., 'hi' => .., 'mr' => ..].
     * @param  array<string, string>  $facts  Token name => fact key.
     */
    private function __construct(
        public string $id,
        public string $type,
        public string|array $template,
        public array $facts,
        public ?string $purpose,
        public ?int $maxChars,
    ) {}

    /**
     * @param  string|array<string, string>  $template  Carries a {{token}} for every figure, date and name it states, and no digit of its own.
     * @param  array<int|string, string>  $facts  The fact keys the template states: ['proposed_completion'], or token => fact key
     *                                            when one template is used several times: ['number' => 'bill_2_number'].
     */
    public static function fixed(string $id, string|array $template, array $facts = []): self
    {
        $bound = [];

        foreach ($facts as $token => $key) {
            $token = is_int($token) ? $key : $token;

            if (! is_string($key) || preg_match(Fact::KEY_PATTERN, $key) !== 1 || preg_match(Fact::KEY_PATTERN, (string) $token) !== 1) {
                throw new InvalidArgumentException("Slot '{$id}' names a fact that cannot be used as a key.");
            }

            if (isset($bound[$token])) {
                throw new InvalidArgumentException("Slot '{$id}' declares the token '{$token}' twice.");
            }

            $bound[$token] = $key;
        }

        $slot = new self(self::checkedId($id), self::FIXED, self::checkedTemplate($id, $template), $bound, null, null);
        $inEnglish = self::tokens($slot->english());

        if (array_diff($inEnglish, array_keys($bound)) !== [] || array_diff(array_keys($bound), $inEnglish) !== []) {
            throw new InvalidArgumentException("Slot '{$id}' must declare exactly the facts its template states.");
        }

        return $slot;
    }

    /**
     * @param  string  $purpose  What the sentences are for, as told to the model. English, never shown to a customer.
     * @param  string|array<string, string>  $template  The code text used when no model wrote this slot. It states no figure.
     * @param  ?int  $maxChars  Longest text accepted from a model, when shorter than the general limit.
     */
    public static function free(string $id, string $purpose, string|array $template, ?int $maxChars = null): self
    {
        if (trim($purpose) === '') {
            throw new InvalidArgumentException("Free slot '{$id}' needs a purpose.");
        }

        if ($maxChars !== null && $maxChars < 1) {
            throw new InvalidArgumentException("Free slot '{$id}' has a length limit below one character.");
        }

        return new self(self::checkedId($id), self::FREE, self::checkedTemplate($id, $template), [], trim($purpose), $maxChars);
    }

    public function isFree(): bool
    {
        return $this->type === self::FREE;
    }

    public function isFixed(): bool
    {
        return $this->type === self::FIXED;
    }

    public function english(): string
    {
        return is_array($this->template) ? $this->template['en'] : $this->template;
    }

    /** @return list<string> The fact keys this slot states, in the order declared. */
    public function factKeys(): array
    {
        return array_values(array_unique(array_values($this->facts)));
    }

    /** @return list<string> Each token name found in a template, once. */
    public static function tokens(string $template): array
    {
        preg_match_all(self::TOKEN_PATTERN, $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    private static function checkedId(string $id): string
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException("'{$id}' cannot be used as a slot id.");
        }

        return $id;
    }

    /**
     * @param  string|array<string, string>  $template
     * @return string|array<string, string>
     */
    private static function checkedTemplate(string $id, string|array $template): string|array
    {
        if (is_string($template)) {
            if (trim($template) === '') {
                throw new InvalidArgumentException("Slot '{$id}' has an empty template.");
            }

            return $template;
        }

        foreach (FactFormat::LOCALES as $locale) {
            if (! is_string($template[$locale] ?? null) || trim($template[$locale]) === '') {
                throw new InvalidArgumentException("Slot '{$id}' has no '{$locale}' template.");
            }
        }

        return array_intersect_key($template, array_flip(FactFormat::LOCALES));
    }
}
