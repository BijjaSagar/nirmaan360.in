<?php

namespace Tests\Feature\Seo;

use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Runs the analytics script that ships in the page, exactly as rendered, in a pretend browser (Node).
 * This is where "nothing loads before Accept" and "nothing personal is sent" are proved for the script
 * itself: the harness records every command queued for Google and every file the page fetched.
 *
 * Skipped where Node is not installed. The server side of the same promises is in AnalyticsTest.
 */
class AnalyticsScriptTest extends TestCase
{
    use ReadsPageHead, RefreshDatabase;

    private const ID = 'G-AB12CD34EF';

    private const GOOGLE = 'https://www.googletagmanager.com/gtag/js?id=G-AB12CD34EF';

    private const DENIED = ['consent', 'default', ['ad_storage' => 'denied', 'ad_user_data' => 'denied', 'ad_personalization' => 'denied', 'analytics_storage' => 'denied']];

    private const GRANTED = ['consent', 'update', ['analytics_storage' => 'granted']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        app(PlatformSettings::class)->set(['ga4_measurement_id' => self::ID]);
    }

    /** The script and the settings a real page carries. @return array{0: string, 1: array<string, mixed>} */
    private function fromPage(TestResponse $response): array
    {
        $script = null;

        foreach ($this->document($response)->querySelectorAll('head script:not([type]):not([src])') as $candidate) {
            if (str_contains((string) $candidate->textContent, 'analytics-config')) {
                $script = (string) $candidate->textContent;
            }
        }

        $this->assertNotNull($script, 'The page carries the analytics script.');

        return [$script, $this->analyticsConfig($response)];
    }

    /**
     * Loads the page once per scenario and plays its steps.
     *
     * @param  list<array<string, mixed>>  $scenarios
     * @return array<string, array<string, mixed>> Scenario name => what happened.
     */
    private function browse(string $script, array $scenarios): array
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node is not installed, so the page script cannot be run here.');
        }

        $directory = sys_get_temp_dir().'/n360-analytics-'.bin2hex(random_bytes(6));
        mkdir($directory);
        file_put_contents($directory.'/script.js', $script);
        file_put_contents($directory.'/scenarios.json', json_encode($scenarios));

        try {
            $process = new Process([$node, __DIR__.'/analytics-harness.mjs', $directory.'/script.js', $directory.'/scenarios.json']);
            $process->setTimeout(30)->run();
            $this->assertTrue($process->isSuccessful(), 'The script ran without an error: '.$process->getErrorOutput());
        } finally {
            @unlink($directory.'/script.js');
            @unlink($directory.'/scenarios.json');
            @rmdir($directory);
        }

        return collect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->keyBy('name')->all();
    }

    /** @return list<mixed> The commands queued after the consent default. */
    private function after(array $result): array
    {
        $this->assertSame(self::DENIED, $result['sent'][0], "{$result['name']}: consent starts denied before anything else.");

        return array_slice($result['sent'], 1);
    }

    public function test_nothing_is_fetched_or_sent_until_the_visitor_accepts(): void
    {
        [$script, $config] = $this->fromPage($this->get('/pricing'));

        $results = $this->browse($script, [
            ['name' => 'first visit', 'config' => $config],
            ['name' => 'first visit, page still loading', 'config' => $config, 'readyState' => 'loading'],
            ['name' => 'page finished loading', 'config' => $config, 'readyState' => 'loading', 'steps' => ['ready']],
            ['name' => 'clicks around without answering', 'config' => $config, 'steps' => ['click-elsewhere', 'type-elsewhere', 'click-elsewhere']],
            ['name' => 'declines', 'config' => $config, 'steps' => ['decline']],
            ['name' => 'declined on an earlier visit', 'config' => $config, 'stored' => 'denied'],
            ['name' => 'declined, then uses a calculator', 'config' => $config, 'stored' => 'denied', 'steps' => [['input' => 'calculator_use', 'details' => ['calculator' => 'steel']]]],
        ]);

        foreach ($results as $name => $result) {
            $this->assertSame([], $result['loaded'], "{$name}: Google's code is not fetched.");
            $this->assertSame([], $this->after($result), "{$name}: nothing is queued beyond the denied default.");
            $this->assertSame('', $result['cookiesLeft'], "{$name}: no cookie.");
        }

        $this->assertTrue($results['first visit']['bannerShown'], 'A first-time visitor is asked.');
        $this->assertFalse($results['first visit, page still loading']['bannerShown'], 'The question waits for the page.');
        $this->assertTrue($results['page finished loading']['bannerShown']);
        $this->assertNull($results['first visit']['choice'], 'Nothing is stored until they answer.');

        $this->assertSame('denied', $results['declines']['choice'], 'The refusal is remembered.');
        $this->assertFalse($results['declines']['bannerShown']);
        $this->assertTrue($results['declines']['offSwitch']);
        $this->assertFalse($results['declined on an earlier visit']['bannerShown'], 'Someone who said no is not asked again.');
    }

    public function test_accepting_loads_google_with_the_cleaned_page_and_nothing_else(): void
    {
        [$script, $config] = $this->fromPage($this->get('/contact?type=sales&email=someone%40example.com&name=Ramesh+Patil&phone=1234567890&utm_source=whatsapp'));

        $result = $this->browse($script, [[
            'name' => 'accepts',
            'config' => $config,
            'referrer' => 'https://mail.example.org/inbox/thread?user=ramesh@example.com',
            'steps' => ['accept'],
        ]])['accepts'];

        $this->assertSame([['tag' => 'script', 'src' => self::GOOGLE, 'async' => true]], $result['loaded'], 'Exactly one outside file is fetched: the Google tag for the saved ID.');
        $this->assertSame('granted', $result['choice']);
        $this->assertFalse($result['bannerShown']);
        $this->assertFalse($result['offSwitch']);

        $sent = $this->after($result);

        $this->assertSame(self::GRANTED, $sent[0], 'Only analytics is granted. The three advertising signals stay denied.');
        $this->assertSame(['js', 'date'], $sent[1]);
        $this->assertSame(['config', self::ID, [
            'send_page_view' => true,
            'page_location' => url('/contact').'?utm_source=whatsapp',
            'page_title' => 'Contact the Nirmaan360 team',
            'page_referrer' => 'https://mail.example.org/',
            'allow_google_signals' => false,
            'allow_ad_personalization_signals' => false,
        ]], $sent[2]);
        $this->assertCount(3, $sent);

        // Not the address in the browser, not the full referrer, nobody's details.
        $everything = json_encode($result);

        foreach (['someone@example.com', 'Ramesh', 'Patil', '1234567890', 'ramesh@example.com', 'inbox', 'type=sales', 'user_id', 'www.nirmaan360.in/contact?'] as $private) {
            $this->assertStringNotContainsString($private, $everything);
        }
    }

    public function test_a_returning_visitor_who_accepted_is_counted_without_being_asked_again(): void
    {
        [$script, $config] = $this->fromPage($this->get('/'));

        $results = $this->browse($script, [
            ['name' => 'accepted last week', 'config' => $config, 'stored' => 'granted', 'storedDaysAgo' => 7],
            ['name' => 'accepted seven months ago', 'config' => $config, 'stored' => 'granted', 'storedDaysAgo' => 215],
            ['name' => 'declined seven months ago', 'config' => $config, 'stored' => 'denied', 'storedDaysAgo' => 215],
            ['name' => 'stored value is nonsense', 'config' => $config, 'storedRaw' => '{"choice":"yes please"}'],
            ['name' => 'stored value is not json', 'config' => $config, 'storedRaw' => 'granted'],
        ]);

        $recent = $results['accepted last week'];
        $this->assertSame(self::GOOGLE, $recent['loaded'][0]['src']);
        $this->assertFalse($recent['bannerShown']);
        $this->assertSame(self::GRANTED, $this->after($recent)[0]);
        $this->assertSame(0, $recent['storageWrites']);

        foreach (['accepted seven months ago', 'declined seven months ago', 'stored value is nonsense', 'stored value is not json'] as $name) {
            $this->assertSame([], $results[$name]['loaded'], "{$name}: an old or unreadable answer is not a yes.");
            $this->assertTrue($results[$name]['bannerShown'], "{$name}: the visitor is asked again.");
        }
    }

    public function test_events_carry_only_listed_details_and_are_sent_once(): void
    {
        [$script, $config] = $this->fromPage($this->get('/calculators'));

        $steel = ['input' => 'calculator_use', 'details' => ['calculator' => 'steel']];
        $result = $this->browse($script, [[
            'name' => 'uses calculators',
            'config' => $config,
            'stored' => 'granted',
            'steps' => [
                $steel, $steel, $steel,
                ['input' => 'calculator_use', 'details' => ['calculator' => 'tiles']],
                // Values that are not on the list are dropped, whatever they are.
                ['input' => 'calculator_use', 'details' => ['calculator' => 'ramesh@example.com', 'length' => '12.5', 'name' => 'Ramesh']],
                ['input' => 'sign_up_start', 'details' => ['plan' => 'business', 'email' => 'someone@example.com', 'company' => 'Patil Constructions']],
                ['input' => 'sign_up_start', 'details' => ['plan' => 'Patil Constructions']],
                // An event the site never defined is not sent at all.
                ['input' => 'purchase', 'details' => ['value' => '29990']],
                ['input' => 'page_view', 'details' => ['pageLocation' => 'https://example.com/?email=someone@example.com']],
                'type-elsewhere',
            ],
        ]])['uses calculators'];

        $events = array_values(array_filter($this->after($result), fn (array $call) => $call[0] === 'event'));

        $this->assertSame([
            ['event', 'calculator_use', ['calculator' => 'steel']],
            ['event', 'calculator_use', ['calculator' => 'tiles']],
            ['event', 'calculator_use', []],
            ['event', 'sign_up_start', ['plan' => 'business']],
            ['event', 'sign_up_start', []],
        ], $events);

        foreach (['example.com', 'Ramesh', 'Patil', '12.5', '29990', 'purchase'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($result['sent']));
        }
    }

    public function test_something_done_before_answering_is_sent_only_if_the_answer_is_yes(): void
    {
        [$script, $config] = $this->fromPage($this->get('/calculators'));
        $use = ['input' => 'calculator_use', 'details' => ['calculator' => 'paint']];

        $results = $this->browse($script, [
            ['name' => 'then accepts', 'config' => $config, 'steps' => [$use, 'accept']],
            ['name' => 'then declines', 'config' => $config, 'steps' => [$use, 'decline', $use]],
            ['name' => 'never answers', 'config' => $config, 'steps' => [$use]],
        ]);

        $this->assertSame(['event', 'calculator_use', ['calculator' => 'paint']], last($results['then accepts']['sent']));
        $this->assertSame([], $this->after($results['then declines']));
        $this->assertSame([], $results['then declines']['loaded']);
        $this->assertSame([], $this->after($results['never answers']));
    }

    public function test_events_noted_by_the_server_follow_the_same_rule(): void
    {
        $this->post('/contact', ['type' => 'support', 'name' => 'Ramesh Patil', 'email' => 'ramesh@example.com', 'phone' => '1234567890', 'message' => 'Call me']);
        [$script, $config] = $this->fromPage($this->get('/contact'));

        $this->assertSame([['name' => 'generate_lead', 'params' => ['topic' => 'support']]], $config['events']);

        $results = $this->browse($script, [
            ['name' => 'accepted before', 'config' => $config, 'stored' => 'granted'],
            ['name' => 'accepts now', 'config' => $config, 'steps' => ['accept']],
            ['name' => 'declined before', 'config' => $config, 'stored' => 'denied'],
            ['name' => 'declines now', 'config' => $config, 'steps' => ['decline']],
            ['name' => 'never answers', 'config' => $config],
        ]);

        $lead = ['event', 'generate_lead', ['topic' => 'support']];

        $this->assertSame($lead, last($results['accepted before']['sent']));
        $this->assertSame($lead, last($results['accepts now']['sent']));

        foreach (['declined before', 'declines now', 'never answers'] as $name) {
            $this->assertSame([], $this->after($results[$name]), "{$name}: the enquiry is not reported.");
            $this->assertSame([], $results[$name]['loaded']);
        }

        $this->assertStringNotContainsString('Ramesh', json_encode($results));
        $this->assertStringNotContainsString('1234567890', json_encode($results));
    }

    public function test_an_app_screen_that_only_passes_on_an_event_counts_no_visit_and_asks_nothing(): void
    {
        [$script] = $this->fromPage($this->get('/'));

        // What the dashboard carries right after a sign-up, when app screens are not included (see AnalyticsTest).
        $config = [
            'id' => self::ID,
            'mode' => 'events',
            'location' => url('/dashboard'),
            'title' => 'dashboard',
            'events' => [['name' => 'sign_up', 'params' => ['method' => 'email', 'plan' => 'contractor']]],
            'allowed' => ['sign_up' => ['method' => ['email'], 'plan' => ['free', 'contractor', 'business']]],
        ];

        $results = $this->browse($script, [
            ['name' => 'accepted on the website', 'config' => $config, 'stored' => 'granted'],
            ['name' => 'never answered', 'config' => $config, 'steps' => ['open']],
            ['name' => 'declined on the website', 'config' => $config, 'stored' => 'denied'],
        ]);

        $accepted = $this->after($results['accepted on the website']);

        $this->assertFalse($accepted[2][2]['send_page_view'], 'The app screen itself is not counted as a visit.');
        $this->assertSame(url('/dashboard'), $accepted[2][2]['page_location']);
        $this->assertSame(['event', 'sign_up', ['method' => 'email', 'plan' => 'contractor']], $accepted[3]);
        $this->assertCount(4, $accepted);

        foreach (['never answered', 'declined on the website'] as $name) {
            $this->assertSame([], $results[$name]['loaded'], "{$name}: nothing is fetched.");
            $this->assertSame([], $this->after($results[$name]));
            $this->assertFalse($results[$name]['bannerShown'], "{$name}: nobody is asked about cookies inside the app.");
        }
    }

    public function test_a_yes_can_be_taken_back_from_cookie_settings(): void
    {
        [$script, $config] = $this->fromPage($this->get('/'));
        $use = ['input' => 'calculator_use', 'details' => ['calculator' => 'brick']];

        $results = $this->browse($script, [
            ['name' => 'opens settings', 'config' => $config, 'stored' => 'granted', 'steps' => ['open']],
            ['name' => 'withdraws', 'config' => $config, 'stored' => 'granted', 'cookies' => '_ga=GA1.1.1.1; _ga_AB12CD34EF=GS1.1.1; XSRF-TOKEN=keep; theme=keep', 'steps' => ['open', 'decline', $use]],
            ['name' => 'changes a no to a yes', 'config' => $config, 'stored' => 'denied', 'steps' => ['open', 'accept']],
            ['name' => 'withdraws and accepts again', 'config' => $config, 'stored' => 'granted', 'steps' => ['open', 'decline', 'open', 'accept', $use]],
        ]);

        $this->assertTrue($results['opens settings']['bannerShown'], 'Cookie settings shows the question again.');

        $withdrawn = $results['withdraws'];
        $this->assertSame('denied', $withdrawn['choice']);
        $this->assertTrue($withdrawn['offSwitch'], "Google's own off switch is set.");
        $this->assertSame(['consent', 'update', ['analytics_storage' => 'denied']], last($withdrawn['sent']), 'Nothing is sent after the refusal, not even the calculator use.');
        $this->assertSame('XSRF-TOKEN=keep; theme=keep', $withdrawn['cookiesLeft'], 'Only the analytics cookies are removed.');
        $this->assertContains('_ga=; Max-Age=0; path=/; domain=nirmaan360.in', $withdrawn['cookieWrites'], 'Removed on the parent domain too, where Google sets it.');

        $this->assertSame('granted', $results['changes a no to a yes']['choice']);
        $this->assertSame(self::GOOGLE, $results['changes a no to a yes']['loaded'][0]['src']);

        $again = $results['withdraws and accepts again'];
        $this->assertCount(1, $again['loaded'], "Google's code is fetched once, however often the answer changes.");
        $this->assertFalse($again['offSwitch']);
        $this->assertSame(['event', 'calculator_use', ['calculator' => 'brick']], last($again['sent']));
    }

    public function test_a_browser_that_blocks_storage_is_asked_and_never_assumed_to_agree(): void
    {
        [$script, $config] = $this->fromPage($this->get('/'));

        $results = $this->browse($script, [
            ['name' => 'blocked', 'config' => $config, 'storageBlocked' => true],
            ['name' => 'blocked, accepts', 'config' => $config, 'storageBlocked' => true, 'steps' => ['accept']],
        ]);

        $this->assertSame([], $results['blocked']['loaded']);
        $this->assertTrue($results['blocked']['bannerShown']);
        $this->assertSame(self::GOOGLE, $results['blocked, accepts']['loaded'][0]['src'], 'The yes still holds for this page.');
    }

    public function test_the_script_does_nothing_on_a_page_without_its_settings(): void
    {
        [$script] = $this->fromPage($this->get('/'));

        $result = $this->browse($script, [['name' => 'no settings', 'steps' => ['accept']]])['no settings'];

        $this->assertSame([], $result['sent']);
        $this->assertSame([], $result['loaded']);
        $this->assertSame([], $result['listeners'], 'It does not even listen.');
    }

    public function test_the_script_never_reads_the_address_bar_or_the_page_title(): void
    {
        [$script] = $this->fromPage($this->get('/'));

        // Everything it reports comes from the settings the server cleaned.
        foreach (['location.href', 'location.pathname', 'location.search', 'document.title', 'document.URL', 'user_id', 'user_properties', 'set\','] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $script, "The script must not use {$forbidden}.");
        }

        $this->assertSame(1, substr_count($script, 'document.referrer ?'), 'The referrer is read in one place, where it is cut down to the site.');
    }
}
