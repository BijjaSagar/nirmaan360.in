<?php

namespace App\Services\Wording;

/**
 * Puts a document together. Figures enter the text in one place only: where a fixed slot's template
 * carries a token and the code fills it from a fact. Free text is copied as it is and never searched
 * for tokens, so nothing a model or a person writes can call up a figure.
 */
final class Renderer
{
    public function __construct(private TemplateLoader $templates, private SlotGuard $guard) {}

    /**
     * @param  array<string, SlotText|array{text?: mixed, source?: mixed}>  $free  Text for free slots by slot id, as stored.
     *                                                                             A slot without text is written from its code text.
     *
     * @throws WordingException When a template or the sheet does not fit the document type.
     * @throws GuardViolation When a numeral would stand in the document without coming from a fact.
     */
    public function render(DocumentType $type, FactSheet $sheet, string $locale, array $free = []): Rendered
    {
        return $this->compose($type, $this->templates->load($type, $locale), $sheet, $locale, $free);
    }

    /**
     * The same with templates already in hand. Guard C runs here, so it holds whatever the templates say.
     *
     * @param  array<string, string>  $templates  Slot id => template in the document's language.
     * @param  array<string, SlotText|array{text?: mixed, source?: mixed}>  $free
     */
    public function compose(DocumentType $type, array $templates, FactSheet $sheet, string $locale, array $free = []): Rendered
    {
        FactFormat::locale($locale);

        $slots = [];
        $labels = [];

        foreach ($type->slots as $slot) {
            $template = $templates[$slot->id] ?? null;

            if (! is_string($template) || trim($template) === '') {
                throw new WordingException("Document type '{$type->key}': slot '{$slot->id}' has no template.");
            }

            $rendered = $slot->isFixed()
                ? $this->fixed($slot, $template, $sheet, $locale)
                : $this->free($slot, $template, $free[$slot->id] ?? null, $sheet, $locale);

            foreach ($rendered->spans as $span) {
                $labels[$span->fact] = $sheet->get($span->fact)->label;
            }

            $slots[] = $rendered;
        }

        return new Rendered($type->key, $locale, $sheet->fingerprint(), $slots, $labels);
    }

    private function fixed(Slot $slot, string $template, FactSheet $sheet, string $locale): RenderedSlot
    {
        if (preg_match_all(Slot::TOKEN_PATTERN, $template, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            throw new WordingException("The template of slot '{$slot->id}' is not valid text.");
        }

        $text = '';
        $plain = '';
        $spans = [];
        $used = [];
        $cursor = 0;

        // One pass over the template. What a fact prints is never read again, so a value that looks like a token stays text.
        foreach ($matches as $match) {
            [$whole, $byte] = $match[0];
            $token = $match[1][0];
            $key = $slot->facts[$token] ?? throw new WordingException("The template of slot '{$slot->id}' states '{$token}', which the slot does not declare.");
            $printed = $sheet->get($key)->display($locale);

            $between = substr($template, $cursor, $byte - $cursor);
            $text .= $between;
            $plain .= $between;
            $spans[] = new Span($key, mb_strlen($text), mb_strlen($printed));
            $text .= $printed;
            $used[$token] = true;
            $cursor = $byte + strlen($whole);
        }

        $text .= substr($template, $cursor);
        $plain .= substr($template, $cursor);

        if (array_diff(array_keys($slot->facts), array_keys($used)) !== []) {
            throw new WordingException("The template of slot '{$slot->id}' leaves out a fact the slot declares.");
        }

        if (str_contains($plain, '{{') || str_contains($plain, '}}')) {
            throw new WordingException("The template of slot '{$slot->id}' has a token that is not written as {{name}}.");
        }

        SpanGuard::assertNone($slot->id, $text, $spans);

        return new RenderedSlot($slot->id, Slot::FIXED, RenderedSlot::CODE, $text, $spans);
    }

    /** @param  SlotText|array{text?: mixed, source?: mixed}|null  $given */
    private function free(Slot $slot, string $template, SlotText|array|null $given, FactSheet $sheet, string $locale): RenderedSlot
    {
        $given = is_array($given) ? SlotText::fromArray($given) : $given;
        $text = $given === null ? '' : trim(str_replace(["\r\n", "\r"], "\n", $given->text));

        if ($given !== null && $text !== '' && $given->source === SlotText::EDITED) {
            // A person's own words. Their numbers are not refused, they are listed for a tick (Guard E).
            return new RenderedSlot($slot->id, Slot::FREE, SlotText::EDITED, $text, [], TypedNumbers::find($slot->id, $text, $sheet, $locale));
        }

        // A model's text is checked again every time it is used: the lists may have grown since it was stored.
        $fromModel = $given !== null && $text !== '' && $given->source === SlotText::AI;
        $keep = $fromModel && $this->guard->passes($text, $locale);
        $text = $keep ? $text : $template;

        SpanGuard::assertNone($slot->id, $text, []);

        return new RenderedSlot($slot->id, Slot::FREE, $keep ? SlotText::AI : SlotText::TEMPLATE, $text, [], [], $fromModel && ! $keep);
    }
}
