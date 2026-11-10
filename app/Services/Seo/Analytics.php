<?php

namespace App\Services\Seo;

use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\URL;

/**
 * Google Analytics 4, switched on by a measurement ID saved in /admin/seo. With no ID nothing is drawn.
 *
 * Nothing personal may reach Google, so this class decides everything that is sent:
 *  - the page address is the route's pattern (/projects/:project/boq), never the address typed,
 *    and its query string is dropped except for plain campaign labels;
 *  - the page title is the website page's own title, or the route name inside the app;
 *  - an event carries only the details listed in events(), each limited to a fixed list of values.
 * The script in resources/views/modules/seo/analytics.blade.php only sends what it is handed here,
 * and loads Google's code only after the visitor accepts.
 */
final class Analytics
{
    /** A GA4 measurement ID: G- and a short run of capital letters and digits, such as G-XXXXXXXXXX. */
    public const ID_PATTERN = '/^G-[A-Z0-9]{4,20}\z/';

    /** The same, as typed into the admin form: small letters are accepted and saved as capitals. */
    public const ID_INPUT_PATTERN = '/^G-[A-Z0-9]{4,20}\z/i';

    /** Campaign labels kept on the page address, so a visit can be traced to the advert that brought it. */
    public const CAMPAIGN_KEYS = ['utm_source', 'utm_medium', 'utm_campaign'];

    private const SESSION_KEY = 'analytics.events';

    private const ATTRIBUTE = 'analytics.page';

    /** No page needs more than a couple of waiting events. */
    private const MAX_WAITING = 10;

    public function __construct(private PlatformSettings $settings, private PublicPages $pages, private SeoText $text) {}

    public function measurementId(): ?string
    {
        $id = strtoupper(trim((string) $this->settings->get('ga4_measurement_id')));

        return preg_match(self::ID_PATTERN, $id) === 1 ? $id : null;
    }

    /** Whether signed-in app screens are measured too. Off until the admin turns it on. */
    public function includesApp(): bool
    {
        return $this->settings->get('ga4_include_app') === '1';
    }

    /**
     * Every event that may be sent and, for each detail it carries, the only values allowed.
     * A value outside its list is dropped, so free text can never ride along.
     *
     * @return array<string, array<string, list<string>>>
     */
    public function events(): array
    {
        $plans = config('buildos.plans');
        $selfServe = array_keys(array_filter($plans, fn (array $plan) => $plan['monthly'] !== null));
        $buyable = array_keys(array_filter($plans, fn (array $plan) => $plan['buyable']));

        return [
            // Someone started typing in the sign-up form.
            'sign_up_start' => ['plan' => $selfServe],
            // A company was created.
            'sign_up' => ['method' => ['email'], 'plan' => $selfServe],
            // A plan was chosen and the payment page opened. No amount is sent.
            'begin_checkout' => ['plan' => $buyable, 'cycle' => ['monthly', 'yearly']],
            // The contact form was sent.
            'generate_lead' => ['topic' => ['sales', 'ai_early_access', 'support']],
            // A number was entered in one of the free calculators.
            'calculator_use' => ['calculator' => ['concrete', 'brick', 'plaster', 'steel', 'tiles', 'paint', 'earth']],
        ];
    }

    /**
     * Notes something that happened on the server, to be sent from the next page this visitor sees.
     * Kept in their session, so it is sent once and only if they accepted analytics.
     *
     * @param  array<string, mixed>  $details
     */
    public function record(Request $request, string $event, array $details = []): void
    {
        if ($this->measurementId() === null || ! $request->hasSession()) {
            return;
        }

        $safe = $this->clean($event, $details);

        if ($safe === null) {
            return;
        }

        $waiting = [...$request->session()->get(self::SESSION_KEY, []), ['name' => $event, 'params' => $safe]];
        $request->session()->put(self::SESSION_KEY, array_slice($waiting, -self::MAX_WAITING));
    }

    /**
     * What the page hands to the script, or null when this page must not load analytics at all.
     * "pageview" pages count a visit and ask for consent. An "events" page is an app screen that only
     * passes on what record() noted, such as a finished sign-up, and counts no visit.
     *
     * @return array{id:string,mode:string,location:string,title:string,events:list<array{name:string,params:array<string,string>}>,allowed:array<string,array<string,list<string>>>}|null
     */
    public function forPage(Request $request, bool $failed = false): ?array
    {
        if ($request->attributes->has(self::ATTRIBUTE)) {
            return $request->attributes->get(self::ATTRIBUTE);
        }

        $page = $this->build($request, $failed);
        $request->attributes->set(self::ATTRIBUTE, $page);

        return $page;
    }

    /** The address reported for this request: the route's pattern, with only plain campaign labels kept. */
    public function location(Request $request): string
    {
        $route = $request->route();
        $path = '';

        if ($route instanceof Route) {
            $localized = str_starts_with((string) $route->getName(), PublicPages::LOCALIZED);

            // A language prefix is one of a few fixed words. Any other slot could name a record, so only its name is sent.
            $path = (string) preg_replace_callback(
                '/\{(\w+)\??\}/',
                fn (array $slot) => $slot[1] === 'locale' && $localized ? app()->getLocale() : ':'.$slot[1],
                $route->uri(),
            );
        }

        $campaign = $this->campaign($request);

        return URL::to('/'.trim($path, '/')).($campaign === [] ? '' : '?'.http_build_query($campaign));
    }

    /** A campaign label is a short tag such as "whatsapp" or "diwali-2026". An email, a phone number or a sentence is not. */
    public static function isPlainLabel(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.\-]{0,63}$/', $value) === 1
            && preg_match_all('/\d/', $value) < 10;
    }

    /** @return array{id:string,mode:string,location:string,title:string,events:list<array<string,mixed>>,allowed:array<string,mixed>}|null */
    private function build(Request $request, bool $failed): ?array
    {
        $id = $this->measurementId();
        $route = $request->route();

        if ($id === null || $failed || ! $route instanceof Route) {
            return null;
        }

        $page = $this->pages->forRoute($route);

        if ($page === null && ! $this->isAppScreen($request, $route)) {
            // Signed links, password resets and the like: their addresses are private and nobody asked to be counted there.
            return null;
        }

        $countsVisits = $page !== null || $this->includesApp();
        $waiting = $request->hasSession() ? $request->session()->get(self::SESSION_KEY, []) : [];

        if (! $countsVisits && $waiting === []) {
            return null;
        }

        if ($waiting !== []) {
            $request->session()->forget(self::SESSION_KEY);
        }

        $events = [];

        foreach ($waiting as $event) {
            $safe = is_array($event) ? $this->clean((string) ($event['name'] ?? ''), (array) ($event['params'] ?? [])) : null;

            if ($safe !== null) {
                $events[] = ['name' => $event['name'], 'params' => $safe];
            }
        }

        return [
            'id' => $id,
            'mode' => $countsVisits ? 'pageview' : 'events',
            'location' => $this->location($request),
            // An app screen's own title can hold a project or company name, so it is never used.
            'title' => $page !== null
                ? $this->text->fullTitle($this->text->title($page, app()->getLocale()))
                : (string) ($route->getName() ?: $route->uri()),
            'events' => $events,
            'allowed' => $this->events(),
        ];
    }

    /** A screen behind sign-in, seen by a member of the company. Outside contractors are never measured. */
    private function isAppScreen(Request $request, Route $route): bool
    {
        $user = $request->user();

        return $user instanceof User
            && ! $user->isContractor()
            && in_array('auth', $route->gatherMiddleware(), true);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, string>|null Null when the event is not one of ours.
     */
    private function clean(string $event, array $details): ?array
    {
        $allowed = $this->events()[$event] ?? null;

        if ($allowed === null) {
            return null;
        }

        $safe = [];

        foreach ($allowed as $key => $values) {
            if (isset($details[$key]) && is_string($details[$key]) && in_array($details[$key], $values, true)) {
                $safe[$key] = $details[$key];
            }
        }

        return $safe;
    }

    /** @return array<string, string> */
    private function campaign(Request $request): array
    {
        $kept = [];

        foreach (self::CAMPAIGN_KEYS as $key) {
            $value = $request->query($key);

            if (is_string($value) && self::isPlainLabel($value)) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }
}
