{{--
    Describes the questions a page shows to search engines (schema.org FAQPage).
    $faqs is a list of [question, answer] in exactly the wording drawn on the page, so the two cannot differ.
    Include it only next to questions that are really on the page.
--}}
<script type="application/ld+json">{!! json_encode(app(\App\Services\Seo\StructuredData::class)->faq($faqs), \App\Services\Seo\StructuredData::JSON) !!}</script>
