<?php

namespace App\Services\Seo;

use App\Support\PlatformSettings;
use Illuminate\Support\Facades\URL;

/**
 * schema.org descriptions of the company and the product, for search engines.
 * Everything comes from what the site already shows: nothing here is a rating, a review or a claim.
 * A value that is not set is left out rather than sent empty.
 */
final class StructuredData
{
    /** How the result is written into a <script> tag. Tags and ampersands are escaped so text cannot close it. */
    public const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;

    private const SOCIAL = ['facebook', 'instagram', 'linkedin', 'youtube', 'x'];

    private const LANGUAGE_NAMES = ['en' => 'English', 'hi' => 'Hindi', 'mr' => 'Marathi'];

    public function __construct(private PublicPages $pages, private SeoText $text, private PlatformSettings $settings) {}

    /** @return list<array<string,mixed>> What a page carries in its head. FAQs are added by the page itself. */
    public function forPage(string $page, string $locale): array
    {
        return match ($page) {
            'home' => [$this->organization($locale), $this->website(), $this->software($locale)],
            'pricing' => [$this->software($locale)],
            'contact' => [$this->organization($locale)],
            default => [],
        };
    }

    /** @return array<string,mixed> */
    public function organization(string $locale): array
    {
        $email = $this->settings->get('contact_email');
        $phone = $this->settings->get('contact_phone');

        return $this->withoutEmpty([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => $this->id('organization'),
            'name' => config('buildos.brand'),
            'url' => $this->pages->url('home'),
            'logo' => $this->logo(),
            'description' => $this->text->description('home', $locale),
            'email' => $email,
            'telephone' => $phone,
            'address' => $this->settings->get('contact_address'),
            'areaServed' => 'IN',
            'sameAs' => array_values(array_filter(array_map(fn (string $network) => $this->settings->get('social_'.$network), self::SOCIAL))),
            'contactPoint' => $email || $phone ? $this->withoutEmpty([
                '@type' => 'ContactPoint',
                'contactType' => 'customer support',
                'email' => $email,
                'telephone' => $phone,
                'areaServed' => 'IN',
                'availableLanguage' => $this->languageNames(),
            ]) : null,
        ]);
    }

    /** @return array<string,mixed> */
    public function website(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => $this->id('website'),
            'name' => config('buildos.brand'),
            'url' => $this->pages->url('home'),
            'inLanguage' => array_keys(config('buildos.locales')),
            'publisher' => ['@id' => $this->id('organization')],
        ];
    }

    /**
     * The product and what each plan costs, read from the plan settings so it follows the admin panel.
     * A plan priced by conversation has no offer, because there is no price to state.
     *
     * @return array<string,mixed>
     */
    public function software(string $locale): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            '@id' => $this->id('software'),
            'name' => config('buildos.brand'),
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'url' => $this->pages->url('home', $locale),
            'description' => $this->text->description('home', $locale),
            'inLanguage' => array_keys(config('buildos.locales')),
            'publisher' => ['@id' => $this->id('organization')],
            'offers' => $this->offers($locale),
        ];
    }

    /**
     * @param  list<array{0:string,1:string}>  $faqs  Question and answer, exactly as the page shows them.
     * @return array<string,mixed>
     */
    public function faq(array $faqs): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (array $faq) => [
                '@type' => 'Question',
                'name' => $faq[0],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq[1]],
            ], array_values($faqs)),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function offers(string $locale): array
    {
        $gst = $this->gstIncluded();
        $url = $this->pages->url('pricing', $locale);
        $offers = [];

        foreach (config('buildos.plans') as $plan) {
            if ($plan['monthly'] === null) {
                continue;
            }

            $label = __($plan['label'], [], $locale);

            if ((int) $plan['monthly'] === 0) {
                $offers[] = ['@type' => 'Offer', 'name' => $label, 'price' => '0', 'priceCurrency' => 'INR', 'url' => $url];

                continue;
            }

            foreach (['monthly' => ['MON', 'month', 'Monthly'], 'yearly' => ['ANN', 'year', 'Yearly']] as $cycle => [$code, $unit, $cycleLabel]) {
                $price = (string) (int) $plan[$cycle];

                $offers[] = [
                    '@type' => 'Offer',
                    'name' => $label.' · '.__($cycleLabel, [], $locale),
                    'price' => $price,
                    'priceCurrency' => 'INR',
                    'url' => $url,
                    'priceSpecification' => $this->withoutEmpty([
                        '@type' => 'UnitPriceSpecification',
                        'price' => $price,
                        'priceCurrency' => 'INR',
                        'unitCode' => $code,
                        'unitText' => $unit,
                        'valueAddedTaxIncluded' => $gst,
                    ]),
                ];
            }
        }

        return $offers;
    }

    /** Whether the stated price already has GST in it. Null when no GST is charged at all. */
    private function gstIncluded(): ?bool
    {
        if ((int) config('buildos.subscription_gst_pct') === 0) {
            return null;
        }

        return match (config('buildos.subscription_gst_mode')) {
            'included' => true,
            'none' => null,
            default => false,
        };
    }

    /**
     * The same choice the <x-brand> component makes: an uploaded logo, else the built-in one,
     * and none at all when the admin shows the company name as text instead.
     *
     * @return array<string,mixed>|string|null
     */
    private function logo(): array|string|null
    {
        if ($this->settings->has('logo_path')) {
            return URL::to('/brand/logo');
        }

        if ($this->settings->has('site_name')) {
            return null;
        }

        return ['@type' => 'ImageObject', 'url' => asset('images/logo.webp'), 'width' => 720, 'height' => 166];
    }

    /** @return list<string> */
    private function languageNames(): array
    {
        return array_values(array_map(fn (string $code) => self::LANGUAGE_NAMES[$code] ?? $code, array_keys(config('buildos.locales'))));
    }

    private function id(string $fragment): string
    {
        return $this->pages->url('home').'#'.$fragment;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function withoutEmpty(array $data): array
    {
        return array_filter($data, fn ($value) => $value !== null && $value !== '' && $value !== []);
    }
}
