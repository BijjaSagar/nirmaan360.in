<?php

namespace App\Services\Seo;

/** What one page tells search engines and link previews. Drawn by resources/views/modules/seo/head.blade.php. */
final class PageMeta
{
    public const HIDDEN = 'noindex, nofollow';

    public const LISTED = 'index, follow, max-image-preview:large';

    /**
     * @param  array<string,string>  $alternates  hreflang code => address, including x-default. Empty when the page has one address.
     * @param  array{url:string,width:?int,height:?int,type:?string}|null  $image
     * @param  list<array<string,mixed>>  $structuredData
     * @param  array<string,string>  $verification  Meta tag name => a search engine's ownership code.
     */
    public function __construct(
        public readonly ?string $page = null,
        public readonly string $robots = self::HIDDEN,
        public readonly ?string $title = null,
        public readonly ?string $shareTitle = null,
        public readonly ?string $description = null,
        public readonly ?string $canonical = null,
        public readonly array $alternates = [],
        public readonly ?string $locale = null,
        public readonly ?array $image = null,
        public readonly array $structuredData = [],
        public readonly array $verification = [],
    ) {}

    /** A page search engines may list: a listed website page that rendered normally. */
    public function listed(): bool
    {
        return $this->page !== null;
    }
}
