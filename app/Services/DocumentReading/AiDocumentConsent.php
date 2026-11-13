<?php

namespace App\Services\DocumentReading;

use App\Models\AiDocumentSettings;
use App\Models\Organization;

/**
 * A company's agreement to the notice about sending its pages, photos and text to the AI provider.
 * The notice has a version. A new wording is a new version, and nothing more is sent for a company
 * until an owner or admin has agreed to it.
 *
 * The switch itself, and what turning it off does to reads in progress, is in ReaderSettings.
 */
final class AiDocumentConsent
{
    public const VERSION = 'ai-docs-v3';

    /**
     * Text calls that send words a person wrote or spoke, as they are: what was heard for a voice
     * report, and hindrance notes from daily reports. They need the switch, like every reader.
     *
     * The other text calls are not under the switch, and the notice says so (sentences()): rate
     * suggestions and translations send the descriptions of BOQ, rate library and quotation lines that
     * the company wrote, and the sentences the AI writes for letters, quotations, weekly updates and
     * bill checks send the labels the code prepared. None of them sends a rate, a quantity or an amount
     * of a record, and each is redacted before it is sent (TextRequest).
     */
    public const TEXT_PURPOSES = ['voice', 'hindrance_groups'];

    /** Whether the company has the readers switched on under the notice as it reads today. */
    public static function current(Organization|int $organization): bool
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;
        $settings = AiDocumentSettings::withoutGlobalScopes()->where('organization_id', $id)->first();

        return $settings !== null && $settings->enabled && $settings->consent_version === self::VERSION;
    }

    /**
     * What a new read stores as its consent_version: the notice its company has agreed to, or null
     * when it has agreed to none that counts today. A read without the current version is never sent.
     */
    public static function agreedVersion(Organization|int $organization): ?string
    {
        return self::current($organization) ? self::VERSION : null;
    }

    /**
     * Whether a feature sends a company's own documents or words, and so needs the switch.
     *
     * @param  string  $feature  A reader kind or a text purpose.
     */
    public static function covers(string $feature): bool
    {
        return in_array($feature, self::TEXT_PURPOSES, true) || ! array_key_exists($feature, config('modules.document_reading.purposes', []));
    }

    /**
     * The notice of this version, sentence by sentence, in one language.
     *
     * The sentence about voice notes says that no audio leaves the phone. That has to be shown on real
     * phones first, so it is left out until the platform admin has recorded that it was. It adds no
     * permission: voice text is "text you choose", which the first sentence already covers.
     *
     * Changing any sentence here means a new VERSION. The Hindi and Marathi lines are in
     * lang/modules/document_reading.
     *
     * @return list<string>
     */
    public static function sentences(?string $locale = null, bool $voice = false): array
    {
        return array_values(array_filter([
            __('The reader sends the pages, photos or text you choose to Anthropic, the company whose AI model reads them.', [], $locale),
            __('They are processed on its servers outside India.', [], $locale),
            __('They are sent as they are: names, phone numbers, GSTINs and amounts on the page are not hidden.', [], $locale),
            __('Location and phone details stored inside a photo file are removed before the photo is kept or sent.', [], $locale),
            __('Anthropic states that it deletes what it receives through its API within 30 days, except in the cases its policy lists, such as when it needs to keep something longer to enforce its usage policy or the law requires it, and that by default it does not use it to train its models.', [], $locale),
            $voice ? __('Voice notes are offered only on phones that can turn speech into text on the phone itself; only that text is sent and no audio leaves the phone.', [], $locale) : null,
            __('Hindrance notes from your daily reports are sent only when you ask for them to be grouped.', [], $locale),
            __('What the reader returns can be wrong, so your staff must check every value against the page before saving.', [], $locale),
            __('By switching this on you confirm that your company is allowed to share these documents in this way.', [], $locale),
            __('You can switch it off at any time, and the readers send nothing new after that.', [], $locale),
            __('This switch does not cover the other AI features: rate suggestions, translations of quotation lines, and the sentences the AI writes for letters, quotations, weekly updates and bill checks. When someone asks for one of them, the descriptions and labels it works on are sent to Anthropic, but not the rates, quantities or amounts of your records.', [], $locale),
        ]));
    }

    /** The notice as one paragraph: the text a company agrees to, and the text kept on its row. */
    public static function notice(?string $locale = null, bool $voice = false): string
    {
        return implode(' ', self::sentences($locale, $voice));
    }

    /** What the audit trail keeps of a notice instead of its words. */
    public static function hash(string $notice): string
    {
        return hash('sha256', $notice);
    }
}
