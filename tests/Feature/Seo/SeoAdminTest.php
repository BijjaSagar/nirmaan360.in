<?php

namespace Tests\Feature\Seo;

use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Seo\Analytics;
use App\Services\Seo\Seo;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\BuildsTenant;
use Tests\TestCase;

/** /admin/seo: page titles and descriptions per language, Google Analytics, ownership codes and the share picture. */
class SeoAdminTest extends TestCase
{
    use BuildsTenant, ReadsPageHead, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private ?User $admin = null;

    private function admin(): User
    {
        $this->admin = $this->member($this->tenant(), 'owner');
        $this->admin->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($this->admin);

        return $this->admin;
    }

    /** @param  array<string, mixed>  $fields */
    private function save(array $fields = [], string $locale = 'en')
    {
        return $this->patch('/admin/seo', ['locale' => $locale, ...$fields]);
    }

    /**
     * The tags of a page as a visitor with no cookies gets it: signed out and in no chosen language.
     * The admin is signed back in afterwards, ready for the next save.
     *
     * @return array<string, mixed>
     */
    private function visit(string $path): array
    {
        Auth::forgetUser();
        $this->flushSession();
        app()->setLocale('en');

        try {
            return $this->tags($this->get($path)->assertOk());
        } finally {
            if ($this->admin) {
                $this->actingAs($this->admin);
            }
        }
    }

    public function test_only_platform_admins_reach_the_screen(): void
    {
        $this->get('/admin/seo')->assertRedirect('/login');
        $this->patch('/admin/seo', ['locale' => 'en', 'ga4_measurement_id' => 'G-AB12CD34EF'])->assertRedirect('/login');

        $this->actingAs($this->member($this->tenant(), 'owner'));
        $this->get('/admin/seo')->assertNotFound();
        $this->patch('/admin/seo', ['locale' => 'en', 'ga4_measurement_id' => 'G-AB12CD34EF'])->assertNotFound();

        $this->actingAs($this->member($this->tenant('Other Co'), 'engineer'));
        $this->get('/admin/seo')->assertNotFound();

        $this->assertSame(0, PlatformSetting::count());
    }

    public function test_the_screen_shows_every_page_in_each_language(): void
    {
        $this->admin();

        $this->get('/admin/seo')->assertOk()
            ->assertSee('Search and analytics')
            ->assertSee('Construction software for Indian contractors')
            ->assertSee('Pricing for builders and contractors')
            ->assertSee(url('/pricing'))
            ->assertSee(url('/sitemap.xml'))
            ->assertSee('G-XXXXXXXXXX');

        $this->get('/admin/seo?locale=hi')->assertOk()
            ->assertSee('बिल्डरों और ठेकेदारों के लिए कीमत')
            ->assertSee(url('/hi/pricing'));

        $this->get('/admin/seo?locale=mr')->assertOk()->assertSee(url('/mr/calculators'));
        $this->get('/admin/seo?locale=xx')->assertOk()->assertSee(url('/pricing'));

        // The tab is offered from the other admin screens.
        $this->get('/admin/website')->assertOk()->assertSee(url('/admin/seo'));
    }

    public function test_a_title_and_description_can_be_set_for_a_page(): void
    {
        $this->admin();

        $this->save(['pages' => ['pricing' => ['title' => 'Plans and prices for site teams', 'description' => 'One price list for every plan, in rupees.']]])
            ->assertRedirect(url('/admin/seo').'?locale=en')
            ->assertSessionHasNoErrors();

        $tags = $this->visit('/pricing');

        $this->assertSame('Plans and prices for site teams · Nirmaan360', $tags['title']);
        $this->assertSame('One price list for every plan, in rupees.', $tags['description']);
        $this->assertSame('Plans and prices for site teams', $tags['meta']['og:title'][0]);
        $this->assertSame('One price list for every plan, in rupees.', $tags['meta']['twitter:description'][0]);

        // Other pages keep their own wording, and the screen shows what was saved.
        $this->assertSame('Construction software for Indian contractors · Nirmaan360', $this->visit('/')['title']);
        $this->get('/admin/seo')->assertSee('Plans and prices for site teams');
    }

    public function test_each_language_is_edited_on_its_own(): void
    {
        $this->admin();

        $this->save(['pages' => ['home' => ['title' => 'साइट का पूरा हिसाब']]], 'hi')->assertSessionHasNoErrors();

        $this->assertSame('साइट का पूरा हिसाब · Nirmaan360', $this->visit('/hi')['title']);
        $this->assertSame('Construction software for Indian contractors · Nirmaan360', $this->visit('/')['title'], 'English is untouched.');
        $this->assertSame('भारतीय ठेकेदारांसाठी बांधकाम सॉफ्टवेअर · Nirmaan360', $this->visit('/mr')['title'], 'Marathi is untouched.');

        // Saving English afterwards does not wipe the Hindi title.
        $this->save(['pages' => ['home' => ['title' => 'Site records that add up']]]);
        $this->assertSame('साइट का पूरा हिसाब · Nirmaan360', $this->visit('/hi')['title']);
        $this->assertSame('Site records that add up · Nirmaan360', $this->visit('/')['title']);
    }

    public function test_clearing_a_box_brings_back_the_built_in_wording(): void
    {
        $this->admin();
        $this->save(['pages' => ['ai' => ['title' => 'Our agents', 'description' => 'Short.']]]);
        $this->assertSame('Our agents · Nirmaan360', $this->visit('/ai')['title']);

        $this->save(['pages' => ['ai' => ['title' => '', 'description' => '   ']]])->assertSessionHasNoErrors();

        $tags = $this->visit('/ai');
        $this->assertSame('AI agents for construction paperwork · Nirmaan360', $tags['title']);
        $this->assertStringStartsWith('AI agents for construction paperwork, from daily reports', $tags['description']);
        $this->assertSame(0, PlatformSetting::where('key', 'like', 'seo_ai_%')->count(), 'Nothing is kept for a cleared box.');
    }

    public function test_the_site_name_and_trial_length_can_be_written_as_placeholders(): void
    {
        $this->admin();
        $this->save(['pages' => ['register' => ['title' => 'Try :brand for :days days', 'description' => ':brand gives every new company :days days on a paid plan.']]]);

        $tags = $this->visit('/register');
        $this->assertSame('Try Nirmaan360 for 30 days', $tags['title'], 'The site name is not added twice.');
        $this->assertSame('Nirmaan360 gives every new company 30 days on a paid plan.', $tags['description']);

        app(PlatformSettings::class)->set(['trial_days' => '14']);
        $this->assertSame('Try Nirmaan360 for 14 days', $this->visit('/register')['title']);
    }

    public function test_titles_and_descriptions_are_kept_as_one_line_of_plain_text(): void
    {
        $this->admin();
        $this->save(['pages' => ['contact' => ['title' => "  Talk to   <b>us</b>\n today ", 'description' => 'Call <script>alert(1)</script> or "write" & we reply.']]])->assertSessionHasNoErrors();

        $response = $this->get('/contact')->assertOk();
        $tags = $this->tags($response);

        $this->assertSame('Talk to us today · Nirmaan360', $tags['title']);
        $this->assertSame('Call alert(1) or "write" & we reply.', $tags['description']);
        $this->assertStringNotContainsString('<script>alert(1)', (string) $response->getContent());
    }

    public function test_text_that_is_far_too_long_is_refused(): void
    {
        $this->admin();

        $this->save(['pages' => ['home' => ['title' => str_repeat('a', 121)]]])->assertSessionHasErrors('pages.home.title');
        $this->save(['pages' => ['home' => ['description' => str_repeat('a', 321)]]])->assertSessionHasErrors('pages.home.description');
        $this->save([], 'fr')->assertSessionHasErrors('locale');

        $this->assertSame(0, PlatformSetting::count());
    }

    public function test_only_listed_pages_can_be_given_a_title(): void
    {
        $this->admin();
        $this->save(['pages' => ['dashboard' => ['title' => 'Mine'], 'home' => ['title' => 'Ours']]])->assertSessionHasNoErrors();

        $this->assertSame(['seo_home_en_title'], PlatformSetting::where('key', 'like', 'seo_%')->pluck('key')->all());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function measurementIds(): array
    {
        return [
            // The shape Google issues: G- and ten capital letters and digits.
            'ten letters and digits' => ['G-AB12CD34EF', 'G-AB12CD34EF'],
            'letters before digits' => ['G-KLM7082QRS', 'G-KLM7082QRS'],
            'all letters' => ['G-ABCDEFGHIJ', 'G-ABCDEFGHIJ'],
            'typed in small letters' => ['g-ab12cd34ef', 'G-AB12CD34EF'],
            'with spaces around it' => ['  G-AB12CD34EF ', 'G-AB12CD34EF'],
        ];
    }

    #[DataProvider('measurementIds')]
    public function test_a_measurement_id_in_googles_format_is_accepted(string $typed, string $saved): void
    {
        $this->admin();
        $this->save(['ga4_measurement_id' => $typed])->assertSessionHasNoErrors();

        $this->assertSame($saved, app(Analytics::class)->measurementId());
        $this->get('/admin/seo')->assertSee($saved);
    }

    /** @return array<string, array{0: string}> */
    public static function notMeasurementIds(): array
    {
        return [
            'an old Universal Analytics id' => ['UA-12345678-1'],
            'a Tag Manager id' => ['GTM-AB12CD3'],
            'a Google Ads id' => ['AW-123456789'],
            'only the prefix' => ['G-'],
            'too short' => ['G-AB1'],
            'a space inside' => ['G-AB12 CD34EF'],
            'markup' => ['G-AB12CD34EF"><script>'],
            'a quote' => ["G-AB12CD34EF'"],
            'a second line' => ["G-AB12CD34EF\nG-ZZ99ZZ99ZZ"],
            'a web address' => ['https://example.com/G-AB12CD34EF'],
        ];
    }

    #[DataProvider('notMeasurementIds')]
    public function test_anything_else_is_refused_as_a_measurement_id(string $typed): void
    {
        $this->admin();
        $this->save(['ga4_measurement_id' => $typed])->assertSessionHasErrors('ga4_measurement_id');

        $this->assertNull(app(Analytics::class)->measurementId());
        $this->assertSame(0, PlatformSetting::count());
    }

    public function test_the_measurement_id_can_be_removed_again(): void
    {
        $this->admin();
        $this->save(['ga4_measurement_id' => 'G-AB12CD34EF', 'ga4_include_app' => '1']);
        $this->assertTrue(app(Analytics::class)->includesApp());

        $this->save(['ga4_measurement_id' => ''])->assertSessionHasNoErrors();

        $this->assertNull(app(Analytics::class)->measurementId());
        $this->assertFalse(app(Analytics::class)->includesApp(), 'An unticked box switches app screens off again.');
        $this->assertNull($this->analyticsConfig($this->get('/')));
    }

    public function test_no_measurement_id_ships_in_the_code_or_config(): void
    {
        $this->assertNull(app(Analytics::class)->measurementId(), 'A fresh install has analytics off.');

        foreach (['.env.example', 'config/services.php', 'config/buildos.php', 'app/Services/Seo/Analytics.php', 'resources/views/modules/seo/analytics.blade.php', 'resources/views/modules/seo/analytics-script.blade.php'] as $file) {
            preg_match_all('/G-[A-Z0-9]{6,}/', (string) file_get_contents(base_path($file)), $found);

            $this->assertSame([], array_values(array_diff($found[0], ['G-XXXXXXXXXX'])), "{$file} must not carry a measurement ID.");
        }
    }

    public function test_ownership_codes_are_printed_on_public_pages_only(): void
    {
        $this->admin();

        $this->save([
            'google_site_verification' => 'aB3_dE-6gH9jK2mN5pQ8rS1tU4vW7xY0zA3bC6dE9fG',
            'bing_site_verification' => '0123456789ABCDEF0123456789ABCDEF',
            'yandex_site_verification' => '0a1b2c3d4e5f6a7b',
        ])->assertSessionHasNoErrors();

        foreach (['/', '/pricing', '/hi', '/login'] as $path) {
            $meta = $this->visit($path)['meta'];

            $this->assertSame(['aB3_dE-6gH9jK2mN5pQ8rS1tU4vW7xY0zA3bC6dE9fG'], $meta['google-site-verification'], $path);
            $this->assertSame(['0123456789ABCDEF0123456789ABCDEF'], $meta['msvalidate.01'], $path);
            $this->assertSame(['0a1b2c3d4e5f6a7b'], $meta['yandex-verification'], $path);
        }

        foreach (['/dashboard', '/admin/seo', '/billing', '/login/otp', '/nothing-here'] as $path) {
            $meta = $this->tags($this->get($path))['meta'];

            foreach (Seo::VERIFICATION as $name) {
                $this->assertArrayNotHasKey($name, $meta, "{$path} is not a public page, so it carries no {$name} tag");
            }
        }
    }

    public function test_each_ownership_code_is_optional(): void
    {
        $this->admin();
        $this->save(['bing_site_verification' => 'ABCDEF0123456789'])->assertSessionHasNoErrors();

        $meta = $this->visit('/')['meta'];
        $this->assertSame(['ABCDEF0123456789'], $meta['msvalidate.01']);
        $this->assertArrayNotHasKey('google-site-verification', $meta);
        $this->assertArrayNotHasKey('yandex-verification', $meta);

        $this->save(['bing_site_verification' => ''])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('msvalidate.01', $this->visit('/')['meta']);
    }

    /** @return array<string, array{0: string}> */
    public static function brokenCodes(): array
    {
        return [
            'closes the attribute' => ['abc123"><script>alert(1)</script>'],
            'a single quote' => ["abc123' onload='x"],
            'the whole tag' => ['<meta name="google-site-verification" content="abc123DEF456" />'],
            'a space' => ['abc123 DEF456'],
            'a dot' => ['abc123.DEF456'],
            'an equals sign' => ['abc123DEF456='],
            'a slash' => ['abc123/DEF456'],
            'an ampersand' => ['abc&quot;123'],
            'a second line' => ["abc123\nDEF456"],
            'other letters' => ['कोड123'],
            '101 characters' => [str_repeat('a', 101)],
        ];
    }

    #[DataProvider('brokenCodes')]
    public function test_an_ownership_code_is_letters_digits_dash_and_underscore_only(string $code): void
    {
        $this->admin();

        foreach (array_keys(Seo::VERIFICATION) as $field) {
            $this->save([$field => $code])->assertSessionHasErrors($field);
        }

        $this->assertSame(0, PlatformSetting::count());
        $this->assertSame([], app(Seo::class)->verification());
    }

    public function test_a_code_of_exactly_100_characters_is_accepted(): void
    {
        $this->admin();
        $code = str_repeat('aZ09-_', 16).'abcd';
        $this->assertSame(100, strlen($code));

        $this->save(['google_site_verification' => $code])->assertSessionHasNoErrors();

        $this->assertSame([$code], $this->visit('/')['meta']['google-site-verification']);
    }

    public function test_a_bad_code_that_reached_the_database_another_way_is_not_printed(): void
    {
        app(PlatformSettings::class)->set([
            'google_site_verification' => 'x"><script>alert(1)</script>',
            'bing_site_verification' => 'fine_CODE-123',
        ]);

        $response = $this->get('/')->assertOk();
        $meta = $this->tags($response)['meta'];

        $this->assertArrayNotHasKey('google-site-verification', $meta);
        $this->assertSame(['fine_CODE-123'], $meta['msvalidate.01']);
        $this->assertStringNotContainsString('<script>alert(1)', (string) $response->getContent());
    }

    public function test_a_share_picture_can_be_uploaded_and_removed(): void
    {
        Storage::fake();
        $this->admin();

        $this->assertSame(asset('images/share.png'), $this->visit('/')['meta']['og:image'][0]);
        $this->get('/brand/share-image')->assertNotFound();

        $this->save(['share_image' => UploadedFile::fake()->image('share.jpg', 1200, 630)])->assertSessionHasNoErrors();

        $meta = $this->visit('/pricing')['meta'];
        $this->assertStringStartsWith(url('/brand/share-image').'?v=', $meta['og:image'][0]);
        $this->assertSame($meta['og:image'][0], $meta['twitter:image'][0]);
        $this->assertSame(['1200', '630', 'image/jpeg'], [$meta['og:image:width'][0], $meta['og:image:height'][0], $meta['og:image:type'][0]]);
        $this->get('/brand/share-image')->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $this->save(['remove_share_image' => '1'])->assertSessionHasNoErrors();

        $this->assertSame(asset('images/share.png'), $this->visit('/')['meta']['og:image'][0]);
        $this->get('/brand/share-image')->assertNotFound();
        $this->assertSame([], Storage::allFiles());
    }

    public function test_a_share_picture_must_be_a_picture_of_a_usable_size(): void
    {
        Storage::fake();
        $this->admin();

        $this->save(['share_image' => UploadedFile::fake()->image('small.png', 300, 150)])->assertSessionHasErrors('share_image');
        $this->save(['share_image' => UploadedFile::fake()->create('share.svg', 10, 'image/svg+xml')])->assertSessionHasErrors('share_image');
        $this->save(['share_image' => UploadedFile::fake()->create('share.pdf', 10, 'application/pdf')])->assertSessionHasErrors('share_image');

        $this->assertSame([], Storage::allFiles());
    }

    public function test_saving_is_written_to_the_audit_log_by_name_only(): void
    {
        $admin = $this->admin();
        $this->save(['ga4_measurement_id' => 'G-AB12CD34EF', 'google_site_verification' => 'secretLookingCode123', 'pages' => ['home' => ['title' => 'A new home title']]]);

        $log = AuditLog::withoutGlobalScopes()->where('action', 'platform.settings_changed')->sole();

        $this->assertSame($admin->id, $log->user_id);
        $this->assertContains('ga4_measurement_id', $log->new_values['settings']);
        $this->assertContains('seo_home_en_title', $log->new_values['settings']);
        $this->assertStringNotContainsString('A new home title', json_encode($log->new_values));
        $this->assertStringNotContainsString('secretLookingCode123', json_encode($log->new_values));
    }
}
