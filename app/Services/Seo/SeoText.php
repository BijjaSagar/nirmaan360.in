<?php

namespace App\Services\Seo;

use App\Support\PlatformSettings;

/**
 * The title and description of each website page: what the platform admin typed for a language,
 * or else the built-in wording, which has Hindi and Marathi in lang/modules/seo.
 */
final class SeoText
{
    public const FIELDS = ['title', 'description'];

    /** Longest a search result shows before cutting the text off. The admin screen counts against these. */
    public const TITLE_LENGTH = 60;

    public const DESCRIPTION_LENGTH = 160;

    /** Built-in wording. :brand is the site name and :days the trial length, so both follow the admin settings. */
    private const DEFAULTS = [
        'home' => [
            'title' => 'Construction software for Indian contractors',
            'description' => 'Construction software for Indian builders and contractors. Track material against the BOQ, contractor payment against work done and time against progress.',
        ],
        'pricing' => [
            'title' => 'Pricing for builders and contractors',
            'description' => 'Plans for Indian builders and contractors, priced in rupees. Start free on one site, or try a paid plan for :days days with no card needed.',
        ],
        'calculators' => [
            'title' => 'Free concrete, steel and brickwork calculators',
            'description' => 'Free calculators for concrete, brickwork, plaster, steel, tiles, paint and cut and fill, in metric units for Indian sites. No sign-up needed.',
        ],
        'ai' => [
            'title' => 'AI agents for construction paperwork',
            'description' => 'AI agents for construction paperwork, from daily reports to RA bills, with a person approving every result.',
        ],
        'contact' => [
            'title' => 'Contact the :brand team',
            'description' => 'Talk to the :brand team about a larger rollout, AI agent early access or a question about the product. Leave your details and we will get back to you.',
        ],
        'privacy' => [
            'title' => 'Privacy policy',
            'description' => 'What :brand collects when you use the website and apps, how we use it, who can see your project records, and your rights under India\'s data protection law.',
        ],
        'terms' => [
            'title' => 'Terms of service',
            'description' => 'The terms for using :brand: your account and data, plans and payment, acceptable use, availability and how to close your account.',
        ],
        'refunds' => [
            'title' => 'Refund and cancellation policy',
            'description' => 'How :brand plans are paid for, how to stop a plan, when a payment is refunded and how long a refund takes.',
        ],
        'account-deletion' => [
            'title' => 'Delete your account',
            'description' => 'How to delete your :brand account in the app or on the website, what is removed at once, what your company keeps and when a company is erased.',
        ],
        'login' => [
            'title' => 'Sign in to your account',
            'description' => 'Sign in to :brand to work on your BOQ, daily reports, measurement book and RA bills.',
        ],
        'register' => [
            'title' => 'Start a :days-day trial',
            'description' => 'Create a :brand account for your company. Start on the free plan, or try a paid plan for :days days with no card needed.',
        ],
    ];

    public function __construct(private PlatformSettings $settings) {}

    /** The name a value is saved under in the platform settings. */
    public static function settingKey(string $page, string $locale, string $field): string
    {
        return "seo_{$page}_{$locale}_{$field}";
    }

    public function title(string $page, string $locale): string
    {
        return $this->text($page, $locale, 'title');
    }

    public function description(string $page, string $locale): string
    {
        return $this->text($page, $locale, 'description');
    }

    /** The whole <title>: the site name follows the page title unless the title already carries it. */
    public function fullTitle(string $title): string
    {
        $brand = (string) config('buildos.brand');

        if ($title === '') {
            return $brand;
        }

        return mb_stripos($title, $brand) !== false ? $title : $title.' · '.$brand;
    }

    public function saved(string $page, string $locale, string $field): ?string
    {
        return $this->settings->get(self::settingKey($page, $locale, $field));
    }

    /** The wording used when the admin has saved nothing for this language. */
    public function builtIn(string $page, string $locale, string $field): string
    {
        return __(self::DEFAULTS[$page][$field], $this->replacements(), $locale);
    }

    private function text(string $page, string $locale, string $field): string
    {
        $saved = $this->saved($page, $locale, $field);

        // The admin can use the same two placeholders, so a trial length typed into a title never goes stale.
        return $saved !== null
            ? strtr($saved, [':brand' => $this->replacements()['brand'], ':days' => $this->replacements()['days']])
            : $this->builtIn($page, $locale, $field);
    }

    /** @return array{brand:string,days:string} */
    private function replacements(): array
    {
        return ['brand' => (string) config('buildos.brand'), 'days' => (string) config('buildos.trial_days')];
    }
}
