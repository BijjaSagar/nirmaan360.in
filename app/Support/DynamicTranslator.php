<?php

namespace App\Support;

use App\Models\SiteText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Translation\Translator;
use Throwable;

/** Lets the platform admin replace any website text, per language, from /admin. */
class DynamicTranslator extends Translator
{
    private const CACHE_KEY = 'site_texts.v1';

    /** @var array<string,array<string,string>>|null */
    private ?array $overrides = null;

    private bool $recording = false;

    /** @var array<string,true> */
    private array $recorded = [];

    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        if ($this->recording && is_string($key) && ! preg_match('/^[a-z_-]+(::|\.)[a-z_.-]+$/i', $key)) {
            $this->recorded[$key] = true;
        }

        $override = $this->overrides()[$locale ?? $this->locale][$key] ?? null;

        if ($override !== null) {
            return $this->makeReplacements($override, $replace);
        }

        return parent::get($key, $replace, $locale, $fallback);
    }

    /** The built-in wording, ignoring anything the admin has saved. */
    public function defaultLine(string $key, string $locale): string
    {
        return parent::get($key, [], $locale);
    }

    public function startRecording(): void
    {
        $this->recording = true;
        $this->recorded = [];
    }

    /** @return list<string> Text keys used since recording started. */
    public function stopRecording(): array
    {
        $this->recording = false;

        return array_map('strval', array_keys($this->recorded));
    }

    public function forgetOverrides(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->overrides = null;
    }

    /** @return array<string,array<string,string>> */
    private function overrides(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        try {
            return $this->overrides = Cache::rememberForever(self::CACHE_KEY, function () {
                $lines = [];

                foreach (SiteText::all(['locale', 'source', 'value']) as $text) {
                    $lines[$text->locale][$text->source] = $text->value;
                }

                return $lines;
            });
        } catch (Throwable) {
            return $this->overrides = [];
        }
    }
}
