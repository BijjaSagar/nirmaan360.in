<?php

namespace App\Services\Seo;

use App\Support\PlatformSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * What the platform admin sets on /admin/seo: page titles and descriptions, Google Analytics,
 * search engine ownership codes and the share picture.
 */
final class SeoSettings
{
    public function __construct(
        private PlatformSettings $settings,
        private PublicPages $pages,
        private SeoText $text,
        private Seo $seo,
        private Analytics $analytics,
    ) {}

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        // Printed inside a meta tag, so nothing but the characters a real code is made of gets through.
        $code = ['nullable', 'string', 'max:100', 'regex:'.Seo::VERIFICATION_PATTERN];

        return [
            'locale' => ['required', Rule::in(array_keys(config('buildos.locales')))],
            'pages' => ['nullable', 'array'],
            'pages.*.title' => ['nullable', 'string', 'max:120'],
            'pages.*.description' => ['nullable', 'string', 'max:320'],
            'ga4_measurement_id' => ['nullable', 'string', 'max:30', 'regex:'.Analytics::ID_INPUT_PATTERN],
            ...array_fill_keys(array_keys(Seo::VERIFICATION), $code),
            'share_image' => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:2048', 'dimensions:min_width=600,min_height=315'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $code = __('Paste only the code from inside content="…". It has letters, digits, - and _ only, and at most 100 characters.');

        return [
            'ga4_measurement_id.regex' => __('Enter a GA4 measurement ID. It starts with G-, like G-XXXXXXXXXX.'),
            ...array_fill_keys(array_map(fn (string $field) => $field.'.regex', array_keys(Seo::VERIFICATION)), $code),
            'share_image.dimensions' => __('The share picture must be at least 600 × 315 pixels. 1200 × 630 works best.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  Validated input.
     * @return list<string> The setting names that were written, for the audit log.
     */
    public function save(array $data, ?UploadedFile $image, bool $removeImage, bool $includeApp): array
    {
        $locale = $data['locale'];
        $values = [
            'ga4_measurement_id' => filled($data['ga4_measurement_id'] ?? null) ? strtoupper($data['ga4_measurement_id']) : null,
            'ga4_include_app' => $includeApp ? '1' : null,
        ];

        foreach (array_keys(Seo::VERIFICATION) as $setting) {
            $values[$setting] = $data[$setting] ?? null;
        }

        foreach ($this->pages->keys() as $page) {
            foreach (SeoText::FIELDS as $field) {
                if (array_key_exists($field, $data['pages'][$page] ?? [])) {
                    $values[SeoText::settingKey($page, $locale, $field)] = self::oneLine($data['pages'][$page][$field]);
                }
            }
        }

        if ($image !== null) {
            [$width, $height] = getimagesize($image->getRealPath()) ?: [null, null];
            $this->deleteShareImage();
            $values = [...$values, 'share_image_path' => $image->store('platform'), 'share_image_width' => $width, 'share_image_height' => $height, 'share_image_type' => $image->getMimeType()];
        } elseif ($removeImage) {
            $this->deleteShareImage();
            $values = [...$values, 'share_image_path' => null, 'share_image_width' => null, 'share_image_height' => null, 'share_image_type' => null];
        }

        $this->settings->set($values);

        return array_keys($values);
    }

    /**
     * Everything the admin screen draws for one language.
     *
     * @return array<string, mixed>
     */
    public function screen(string $locale): array
    {
        $rows = [];

        foreach ($this->pages->keys() as $page) {
            $rows[] = [
                'page' => $page,
                'label' => $this->pages->label($page),
                'url' => $this->pages->url($page, $locale),
                // A page with one address is still shown in the visitor's language, so its words can be set per language too.
                'ownAddress' => in_array($locale, $this->pages->locales($page), true),
                'title' => $this->text->saved($page, $locale, 'title'),
                'description' => $this->text->saved($page, $locale, 'description'),
                'builtInTitle' => $this->text->builtIn($page, $locale, 'title'),
                'builtInDescription' => $this->text->builtIn($page, $locale, 'description'),
            ];
        }

        $verification = [];

        foreach (array_keys(Seo::VERIFICATION) as $setting) {
            $verification[$setting] = $this->settings->get($setting);
        }

        return [
            'locale' => $locale,
            'rows' => $rows,
            'measurementId' => $this->analytics->measurementId(),
            'includeApp' => $this->analytics->includesApp(),
            'verification' => $verification,
            'shareImage' => $this->seo->shareImage(),
            'hasOwnShareImage' => $this->settings->has('share_image_path'),
        ];
    }

    /** A title or description is one line of plain text. Blank means "use the built-in wording". */
    private static function oneLine(?string $value): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));

        return $text === '' ? null : $text;
    }

    private function deleteShareImage(): void
    {
        $path = $this->settings->get('share_image_path');

        if ($path) {
            Storage::delete($path);
        }
    }
}
