<?php

namespace App\Services\Seo;

use XMLWriter;

/**
 * /sitemap.xml and /robots.txt. The sitemap is built from the routes of the listed website pages:
 * one entry per page and language, each naming its other language versions.
 */
final class Sitemap
{
    public function __construct(private PublicPages $pages, private Seo $seo) {}

    /**
     * @return list<array{loc:string,alternates:array<string,string>}>
     */
    public function entries(): array
    {
        $entries = [];

        foreach ($this->pages->keys() as $page) {
            if (! $this->pages->inSitemap($page)) {
                continue;
            }

            $alternates = $this->seo->alternates($page);

            foreach ($this->pages->locales($page) as $locale) {
                $entries[] = ['loc' => $this->pages->url($page, $locale), 'alternates' => $alternates];
            }
        }

        return $entries;
    }

    public function xml(): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');

        foreach ($this->entries() as $entry) {
            $xml->startElement('url');
            $xml->writeElement('loc', $entry['loc']);

            foreach ($entry['alternates'] as $language => $address) {
                $xml->startElement('xhtml:link');
                $xml->writeAttribute('rel', 'alternate');
                $xml->writeAttribute('hreflang', $language);
                $xml->writeAttribute('href', $address);
                $xml->endElement();
            }

            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * Crawlers may read every page. What must stay out of search results says so itself with noindex,
     * which a crawler can only see if it is allowed to fetch the page. Only endpoints that are not pages are closed.
     */
    public function robots(string $sitemapUrl): string
    {
        return implode("\n", [
            'User-agent: *',
            'Disallow: /api/',
            'Disallow: /locale/',
            'Allow: /',
            '',
            'Sitemap: '.$sitemapUrl,
            '',
        ]);
    }
}
