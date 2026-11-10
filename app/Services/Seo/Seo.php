<?php

namespace App\Services\Seo;

use App\Support\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/** Works out the search and sharing tags for the page being served. */
final class Seo
{
    /** The built-in share picture, made from the logo on the website's own paper colour. */
    public const SHARE_IMAGE = ['path' => 'images/share.png', 'width' => 1200, 'height' => 630, 'type' => 'image/png'];

    /** Facebook and WhatsApp name a language with its country. */
    private const SHARE_LOCALES = ['en' => 'en_IN', 'hi' => 'hi_IN', 'mr' => 'mr_IN'];

    /** Setting => the meta tag each search engine reads to check who owns the site. */
    public const VERIFICATION = [
        'google_site_verification' => 'google-site-verification',
        'bing_site_verification' => 'msvalidate.01',
        'yandex_site_verification' => 'yandex-verification',
    ];

    /** A verification code is letters, digits, - and _ only, so it can never close the attribute it is printed in. */
    public const VERIFICATION_PATTERN = '/^[A-Za-z0-9_-]{1,100}\z/';

    public function __construct(
        private PublicPages $pages,
        private SeoText $text,
        private StructuredData $data,
        private PlatformSettings $settings,
    ) {}

    /**
     * Tags for this request. Only a listed website page that rendered normally may be indexed:
     * app screens, signed links, sign-in helpers and error pages all come back hidden.
     */
    public function meta(Request $request, bool $failed = false): PageMeta
    {
        $page = $failed ? null : $this->pages->forRoute($request->route());

        if ($page === null) {
            return new PageMeta;
        }

        // The words follow the language on screen. The address does too, when the page has one per language.
        $shown = app()->getLocale();
        $locale = $this->pages->localeFor($page, $shown);
        $title = $this->text->title($page, $shown);

        return new PageMeta(
            page: $page,
            robots: PageMeta::LISTED,
            title: $this->text->fullTitle($title),
            shareTitle: $title,
            description: $this->text->description($page, $shown),
            canonical: $this->pages->url($page, $locale),
            alternates: $this->alternates($page),
            locale: $shown,
            image: $this->shareImage(),
            structuredData: $this->data->forPage($page, $shown),
            verification: $this->verification(),
        );
    }

    /**
     * Ownership codes for the search engines the admin has one for. Each is checked again on the way out,
     * so a value that reached the database some other way still cannot break the tag.
     *
     * @return array<string,string> Meta tag name => code.
     */
    public function verification(): array
    {
        $tags = [];

        foreach (self::VERIFICATION as $setting => $name) {
            $code = (string) $this->settings->get($setting);

            if (preg_match(self::VERIFICATION_PATTERN, $code) === 1) {
                $tags[$name] = $code;
            }
        }

        return $tags;
    }

    /** The <title> of a listed page, or null so the layout keeps the title its own view gave. */
    public function title(Request $request, bool $failed = false): ?string
    {
        return $this->meta($request, $failed)->title;
    }

    /** @return array<string,string> */
    public function alternates(string $page): array
    {
        if (! $this->pages->hasLanguages($page)) {
            return [];
        }

        $alternates = [];

        foreach ($this->pages->locales($page) as $locale) {
            $alternates[$locale] = $this->pages->url($page, $locale);
        }

        return [...$alternates, 'x-default' => $this->pages->url($page)];
    }

    /** @return array<string,string> hreflang code => the og:locale spelling. */
    public function shareLocales(): array
    {
        $locales = [];

        foreach (array_keys(config('buildos.locales')) as $locale) {
            $locales[$locale] = self::SHARE_LOCALES[$locale] ?? $locale;
        }

        return $locales;
    }

    /**
     * The picture shown when a page is shared: the one the admin uploaded, or the built-in one.
     *
     * @return array{url:string,width:?int,height:?int,type:?string}
     */
    public function shareImage(): array
    {
        $uploaded = $this->settings->get('share_image_path');

        if ($uploaded) {
            return [
                'url' => URL::to('/brand/share-image').'?v='.substr(md5($uploaded), 0, 8),
                'width' => ((int) $this->settings->get('share_image_width')) ?: null,
                'height' => ((int) $this->settings->get('share_image_height')) ?: null,
                'type' => $this->settings->get('share_image_type'),
            ];
        }

        return [
            'url' => asset(self::SHARE_IMAGE['path']),
            'width' => self::SHARE_IMAGE['width'],
            'height' => self::SHARE_IMAGE['height'],
            'type' => self::SHARE_IMAGE['type'],
        ];
    }
}
