<?php

namespace Tests\Feature\Seo;

use Dom\HTMLDocument;
use Illuminate\Testing\TestResponse;

/**
 * Reads a page the way a browser or crawler does: by parsing it as HTML5, not by searching the text.
 * A tag that is only found when it really sits inside <head> also proves nothing in the head closed it early.
 */
trait ReadsPageHead
{
    protected function document(TestResponse|string $page): HTMLDocument
    {
        $html = $page instanceof TestResponse ? (string) $page->getContent() : $page;

        return HTMLDocument::createFromString($html, LIBXML_NOERROR);
    }

    /**
     * @return array{title:string,robots:?string,description:?string,canonical:?string,lang:string,alternates:array<string,string>,meta:array<string,list<string>>}
     */
    protected function tags(TestResponse|string $page): array
    {
        $document = $this->document($page);
        $meta = [];

        foreach ($document->querySelectorAll('head meta[content]') as $tag) {
            $name = $tag->getAttribute('name') ?: $tag->getAttribute('property');

            if ($name) {
                $meta[$name][] = (string) $tag->getAttribute('content');
            }
        }

        $alternates = [];

        foreach ($document->querySelectorAll('head link[rel="alternate"][hreflang]') as $link) {
            $alternates[(string) $link->getAttribute('hreflang')] = (string) $link->getAttribute('href');
        }

        $canonical = $document->querySelectorAll('head link[rel="canonical"]');

        return [
            'title' => trim((string) $document->querySelector('head title')?->textContent),
            'robots' => $meta['robots'][0] ?? null,
            'description' => $meta['description'][0] ?? null,
            'canonical' => $canonical->length === 1 ? (string) $canonical->item(0)->getAttribute('href') : null,
            'lang' => (string) $document->documentElement?->getAttribute('lang'),
            'alternates' => $alternates,
            'meta' => $meta,
        ];
    }

    /**
     * Every structured data block on the page, parsed. A block that is not valid JSON fails the test.
     *
     * @return list<array<string,mixed>>
     */
    protected function structuredData(TestResponse|string $page): array
    {
        $blocks = [];

        foreach ($this->document($page)->querySelectorAll('script[type="application/ld+json"]') as $script) {
            $blocks[] = json_decode((string) $script->textContent, true, flags: JSON_THROW_ON_ERROR);
        }

        return $blocks;
    }

    /** @return array<string,mixed>|null The first structured data block of a type. */
    protected function structuredDataOf(TestResponse|string $page, string $type): ?array
    {
        foreach ($this->structuredData($page) as $block) {
            if (($block['@type'] ?? null) === $type) {
                return $block;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null What the page hands to the analytics script, or null when it has none. */
    protected function analyticsConfig(TestResponse|string $page): ?array
    {
        $holder = $this->document($page)->querySelector('script#analytics-config');

        return $holder === null ? null : json_decode((string) $holder->textContent, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> Every link in the page body, as written. */
    protected function bodyLinks(TestResponse|string $page): array
    {
        $links = [];

        foreach ($this->document($page)->querySelectorAll('body a[href]') as $link) {
            $links[] = (string) $link->getAttribute('href');
        }

        return $links;
    }
}
