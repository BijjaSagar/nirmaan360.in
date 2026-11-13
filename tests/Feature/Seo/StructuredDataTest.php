<?php

namespace Tests\Feature\Seo;

use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsTenant;
use Tests\TestCase;

/**
 * The schema.org blocks search engines read. Every block is parsed as JSON, so a broken one fails here,
 * and what it says is compared with what the page and the plan settings really show.
 */
class StructuredDataTest extends TestCase
{
    use BuildsTenant, ReadsPageHead, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array<string, array<string, mixed>> Offer name => offer. */
    private function offers(string $path): array
    {
        $software = $this->structuredDataOf($this->get($path)->assertOk(), 'SoftwareApplication');
        $this->assertNotNull($software, "{$path} describes the product");

        return collect($software['offers'])->keyBy('name')->all();
    }

    /** @param  array<mixed>  $data */
    private function assertNothingEmpty(array $data, string $where): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->assertNotSame([], $value, "{$where}.{$key} is an empty list");
                $this->assertNothingEmpty($value, "{$where}.{$key}");
            } else {
                $this->assertNotNull($value, "{$where}.{$key} is null");
                $this->assertNotSame('', $value, "{$where}.{$key} is empty");
            }
        }
    }

    public function test_every_block_on_every_public_page_is_valid_and_has_no_empty_values(): void
    {
        $types = [];

        foreach (['/', '/pricing', '/calculators', '/ai', '/contact', '/privacy', '/terms', '/login', '/register', '/hi', '/mr/pricing'] as $path) {
            foreach ($this->structuredData($this->get($path)->assertOk()) as $block) {
                $this->assertSame('https://schema.org', $block['@context'], $path);
                $this->assertArrayHasKey('@type', $block, $path);
                $this->assertNothingEmpty($block, $path.' '.$block['@type']);
                $types[$path][] = $block['@type'];
            }
        }

        $this->assertSame(['Organization', 'WebSite', 'SoftwareApplication', 'FAQPage'], $types['/']);
        $this->assertSame(['SoftwareApplication', 'FAQPage'], $types['/pricing']);
        $this->assertSame(['Organization'], $types['/contact']);
        $this->assertSame($types['/'], $types['/hi']);
    }

    public function test_the_organization_is_described_from_the_site_settings(): void
    {
        $organization = $this->structuredDataOf($this->get('/'), 'Organization');

        $this->assertSame('Nirmaan360', $organization['name']);
        $this->assertSame(url('/'), $organization['url']);
        $this->assertSame(asset('images/logo.webp'), $organization['logo']['url']);
        $this->assertFileExists(public_path('images/logo.webp'));
        $this->assertSame([720, 166], array_slice(getimagesize(public_path('images/logo.webp')), 0, 2), 'The stated logo size is the real one.');
        $this->assertSame([$organization['logo']['width'], $organization['logo']['height']], [720, 166]);

        // Nothing is invented: contact details appear only once the admin has saved them.
        foreach (['email', 'telephone', 'address', 'sameAs', 'contactPoint', 'aggregateRating', 'review'] as $absent) {
            $this->assertArrayNotHasKey($absent, $organization);
        }

        app(PlatformSettings::class)->set([
            'contact_email' => 'hello@example.com',
            'contact_phone' => '+91 00000 00000',
            'contact_address' => 'Pune, Maharashtra',
            'social_linkedin' => 'https://www.linkedin.com/company/example',
            'social_youtube' => 'https://www.youtube.com/@example',
        ]);

        $organization = $this->structuredDataOf($this->get('/'), 'Organization');

        $this->assertSame('hello@example.com', $organization['email']);
        $this->assertSame('+91 00000 00000', $organization['telephone']);
        $this->assertSame('Pune, Maharashtra', $organization['address']);
        $this->assertSame(['https://www.linkedin.com/company/example', 'https://www.youtube.com/@example'], $organization['sameAs']);
        $this->assertSame('customer support', $organization['contactPoint']['contactType']);
        $this->assertSame(['English', 'Marathi', 'Hindi'], $organization['contactPoint']['availableLanguage']);
    }

    public function test_the_organization_follows_the_brand_the_admin_chose(): void
    {
        app(PlatformSettings::class)->set(['site_name' => 'Acme Build']);
        $organization = $this->structuredDataOf($this->get('/'), 'Organization');

        $this->assertSame('Acme Build', $organization['name']);
        $this->assertArrayNotHasKey('logo', $organization, 'The built-in logo is not claimed for another brand name.');
        $this->assertSame('Acme Build', $this->structuredDataOf($this->get('/'), 'WebSite')['name']);
    }

    public function test_the_product_states_the_real_plan_prices(): void
    {
        $offers = $this->offers('/pricing');
        $plans = config('buildos.plans');

        $this->assertSame([
            'Free / Site', 'Contractor · Monthly', 'Contractor · Yearly', 'Business · Monthly', 'Business · Yearly',
        ], array_keys($offers));

        $this->assertSame('0', $offers['Free / Site']['price']);

        foreach (['contractor' => 'Contractor', 'business' => 'Business'] as $key => $label) {
            $this->assertSame((string) $plans[$key]['monthly'], $offers["{$label} · Monthly"]['price']);
            $this->assertSame((string) $plans[$key]['yearly'], $offers["{$label} · Yearly"]['price']);
            $this->assertSame('MON', $offers["{$label} · Monthly"]['priceSpecification']['unitCode']);
            $this->assertSame('ANN', $offers["{$label} · Yearly"]['priceSpecification']['unitCode']);
        }

        foreach ($offers as $name => $offer) {
            $this->assertSame('Offer', $offer['@type'], $name);
            $this->assertSame('INR', $offer['priceCurrency'], $name);
            $this->assertSame(url('/pricing'), $offer['url'], $name);
            $this->assertMatchesRegularExpression('/^\d+$/', $offer['price'], "{$name} has a plain number for a price");
        }

        // The page shows the same figures a visitor reads.
        $this->get('/pricing')->assertSee('₹2,999')->assertSee('₹29,990');
        $this->assertSame('2999', $offers['Contractor · Monthly']['price']);
        $this->assertSame($offers, $this->offers('/'), 'The home page and the pricing page state the same offers.');
    }

    public function test_a_plan_priced_by_conversation_has_no_offer(): void
    {
        $this->assertNull(config('buildos.plans.enterprise.monthly'));

        foreach (array_keys($this->offers('/pricing')) as $name) {
            $this->assertStringNotContainsString('Enterprise', $name);
        }
    }

    public function test_a_price_changed_in_the_admin_panel_reaches_the_structured_data(): void
    {
        $admin = $this->member($this->tenant(), 'owner');
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($admin);

        $plans = [];

        foreach (config('buildos.plans') as $key => $plan) {
            $plans[$key] = ['label' => $plan['label'], 'tagline' => $plan['tagline'], 'max_users' => $plan['max_users'], 'max_projects' => $plan['max_projects'], 'features' => ['site']];

            if ($plan['buyable']) {
                $plans[$key] += ['monthly' => $plan['monthly'], 'yearly' => $plan['yearly']];
            }
        }

        $plans['contractor'] = [...$plans['contractor'], 'label' => 'Builder', 'monthly' => 3499, 'yearly' => 34990];
        $this->patch('/admin/plans', ['plans' => $plans])->assertSessionHasNoErrors();

        $offers = $this->offers('/pricing');

        $this->assertSame('3499', $offers['Builder · Monthly']['price']);
        $this->assertSame('34990', $offers['Builder · Yearly']['price']);
        $this->assertArrayNotHasKey('Contractor · Monthly', $offers);
        $this->assertStringNotContainsString('2999', json_encode($offers));
    }

    public function test_the_gst_setting_is_stated_truthfully(): void
    {
        $taxIncluded = fn () => $this->offers('/pricing')['Contractor · Monthly']['priceSpecification']['valueAddedTaxIncluded'] ?? 'not stated';

        $this->assertFalse($taxIncluded(), 'GST is added on top of the price by default.');

        app(PlatformSettings::class)->set(['subscription_gst_mode' => 'included']);
        $this->assertTrue($taxIncluded());

        app(PlatformSettings::class)->set(['subscription_gst_mode' => 'none']);
        $this->assertSame('not stated', $taxIncluded());
    }

    public function test_the_faq_block_carries_exactly_the_questions_on_the_page(): void
    {
        foreach (['/' => 8, '/pricing' => 4] as $path => $count) {
            $response = $this->get($path)->assertOk();
            $faq = $this->structuredDataOf($response, 'FAQPage');

            $this->assertCount($count, $faq['mainEntity'], "{$path} marks up every question it shows");

            $shown = [];

            foreach ($this->document($response)->querySelectorAll('.faq details') as $item) {
                $shown[] = [
                    trim((string) $item->querySelector('summary')->textContent),
                    trim((string) $item->querySelector('p')->textContent),
                ];
            }

            $marked = array_map(fn (array $question) => [$question['name'], $question['acceptedAnswer']['text']], $faq['mainEntity']);

            $this->assertSame($shown, $marked, "{$path}: the marked-up questions and answers are the ones a visitor reads");

            foreach ($faq['mainEntity'] as $question) {
                $this->assertSame('Question', $question['@type']);
                $this->assertSame('Answer', $question['acceptedAnswer']['@type']);
                $this->assertStringNotContainsString(':days', $question['acceptedAnswer']['text'], 'Placeholders are filled in.');
            }
        }
    }

    public function test_pages_without_questions_have_no_faq_block(): void
    {
        foreach (['/calculators', '/ai', '/contact', '/privacy', '/terms', '/login', '/register'] as $path) {
            $this->assertNull($this->structuredDataOf($this->get($path), 'FAQPage'), "{$path} shows no questions, so it claims none");
        }
    }

    public function test_switching_the_questions_off_removes_the_faq_block(): void
    {
        app(PlatformSettings::class)->set(['section_faq' => '0']);
        $response = $this->get('/')->assertOk()->assertDontSee('Questions builders ask us');

        $this->assertNull($this->structuredDataOf($response, 'FAQPage'));
        $this->assertNotNull($this->structuredDataOf($response, 'Organization'));
    }

    public function test_the_hindi_page_describes_itself_in_hindi(): void
    {
        $response = $this->get('/hi')->assertOk();
        $faq = $this->structuredDataOf($response, 'FAQPage');
        $software = $this->structuredDataOf($response, 'SoftwareApplication');

        $this->assertSame('शुरू करने के लिए कार्ड चाहिए?', $faq['mainEntity'][1]['name']);
        $this->assertSame(url('/hi'), $software['url']);
        $this->assertSame(url('/hi/pricing'), $software['offers'][0]['url']);
        $this->assertStringContainsString('ठेकेदार', $software['description']);
        $this->assertSame('ठेकेदार · मासिक', $software['offers'][1]['name']);
        // Prices are the same in every language.
        $this->assertSame((string) config('buildos.plans.contractor.monthly'), $software['offers'][1]['price']);
    }

    public function test_text_typed_by_the_admin_cannot_break_out_of_the_block(): void
    {
        app(PlatformSettings::class)->set(['site_name' => 'Acme </script><script>alert(1)</script> & Sons']);
        $response = $this->get('/')->assertOk();

        $this->assertStringNotContainsString('</script><script>alert(1)', (string) $response->getContent());
        $this->assertSame('Acme </script><script>alert(1)</script> & Sons', $this->structuredDataOf($response, 'Organization')['name']);
    }

    public function test_app_pages_carry_no_structured_data(): void
    {
        $this->actingAs($this->member($this->tenant(), 'owner'));

        $this->assertSame([], $this->structuredData($this->get('/dashboard')->assertOk()));
    }
}
