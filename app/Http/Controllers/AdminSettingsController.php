<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SiteText;
use App\Models\User;
use App\Services\RazorpayGateway;
use App\Support\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/** Everything the platform owner can change without a deploy. Guarded by EnsurePlatformAdmin. */
class AdminSettingsController extends Controller
{
    public const FEATURES = [
        'site' => 'Daily reports and issues',
        'measurement' => 'Measurement book',
        'labour' => 'Attendance and workers',
        'petty_cash' => 'Petty cash',
        'billing' => 'Work orders and RA bills',
        'parties' => 'Contacts directory',
        'api' => 'API access',
    ];

    /** Home page sections, in page order. Every site_on() key in public/home.blade.php must be here. */
    public const SECTIONS = [
        'strip' => 'Highlights strip under the hero',
        'leaks' => 'Where the money goes',
        'payment' => 'Payment against work',
        'material' => 'Material against BOQ',
        'progress' => 'Progress by floor and time',
        'trades' => 'Plumbing and electrical stages',
        'tender' => 'BOQ, quotes and budget',
        'billing' => 'Measurement book and RA bills',
        'field_app' => 'Mobile app',
        'roles' => 'By role',
        'ai' => 'AI agents',
        'pricing' => 'Pricing preview',
        'faq' => 'Questions',
    ];

    private const WEBSITE_FIELDS = [
        'site_name', 'contact_email', 'info_email', 'contact_phone', 'contact_address',
        'social_facebook', 'social_instagram', 'social_linkedin', 'social_youtube', 'social_x',
        'announcement', 'announcement_link', 'privacy_text', 'terms_text', 'refund_text',
        'color_primary', 'color_primary_dark', 'color_accent',
    ];

    private const PAGES = [
        'home' => 'Home',
        'pricing' => 'Pricing',
        'ai' => 'AI agents',
        'contact' => 'Contact',
        'calculators' => 'Calculators',
        'signup' => 'Sign in and register',
    ];

    public function __construct(private PlatformSettings $settings) {}

    public function plans(): View
    {
        return view('admin.plans', ['plans' => config('buildos.plans'), 'features' => self::FEATURES]);
    }

    public function updatePlans(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'plans' => ['required', 'array'],
            'plans.*.label' => ['required', 'string', 'max:60'],
            'plans.*.tagline' => ['required', 'string', 'max:160'],
            'plans.*.monthly' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'plans.*.yearly' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'plans.*.max_users' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'plans.*.max_projects' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'plans.*.max_devices' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'plans.*.features' => ['array'],
            'plans.*.features.*' => [Rule::in([...array_keys(self::FEATURES), ...array_filter(array_column(config('modules', []), 'feature'))])],
        ]);

        $saved = [];

        foreach (config('buildos.plans') as $key => $plan) {
            $input = $data['plans'][$key] ?? null;

            if (! $input) {
                continue;
            }

            $saved[$key] = [
                'label' => $input['label'],
                'tagline' => $input['tagline'],
                'max_users' => isset($input['max_users']) ? (int) $input['max_users'] : null,
                'max_projects' => isset($input['max_projects']) ? (int) $input['max_projects'] : null,
                'features' => array_values($input['features'] ?? []),
            ];

            // Offline-plan phones. Only saved when the form offered the field, so other plans keep their default.
            if (array_key_exists('max_devices', $input)) {
                $saved[$key]['max_devices'] = isset($input['max_devices']) ? (int) $input['max_devices'] : null;
            }

            // Free stays free and Enterprise stays priced by conversation.
            if ($plan['buyable']) {
                if (! isset($input['monthly'], $input['yearly'])) {
                    throw ValidationException::withMessages(["plans.{$key}.monthly" => __('Enter a monthly and a yearly price for :plan.', ['plan' => $input['label']])]);
                }

                $saved[$key]['monthly'] = (int) $input['monthly'];
                $saved[$key]['yearly'] = (int) $input['yearly'];
            }
        }

        $this->settings->set(['plans' => json_encode($saved)]);
        $this->audit($request, ['plans']);

        return back()->with('success', __('Plans and prices saved.'));
    }

    public function payments(RazorpayGateway $gateway): View
    {
        return view('admin.payments', [
            'keyId' => config('services.razorpay.key_id'),
            'hasSecret' => filled(config('services.razorpay.key_secret')),
            'hasWebhookSecret' => filled(config('services.razorpay.webhook_secret')),
            'live' => $gateway->isLive(),
            'trialDays' => config('buildos.trial_days'),
            'gstPct' => config('buildos.subscription_gst_pct'),
            'gstMode' => config('buildos.subscription_gst_mode'),
            'graceDays' => config('buildos.payment_grace_days'),
        ]);
    }

    public function updatePayments(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'razorpay_key_id' => ['nullable', 'string', 'max:64'],
            'razorpay_key_secret' => ['nullable', 'string', 'max:128'],
            'razorpay_webhook_secret' => ['nullable', 'string', 'max:128'],
            'trial_days' => ['required', 'integer', 'min:1', 'max:365'],
            'subscription_gst_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'subscription_gst_mode' => ['required', 'in:plus,included,none'],
            'payment_grace_days' => ['required', 'integer', 'min:0', 'max:60'],
        ]);

        // A blank secret field means "keep what is saved". It is never shown back.
        $values = $this->withoutBlank($data, ['razorpay_key_secret', 'razorpay_webhook_secret']);

        if ($request->boolean('clear_keys')) {
            $values = [...$values, 'razorpay_key_id' => null, 'razorpay_key_secret' => null, 'razorpay_webhook_secret' => null];
            config(['services.razorpay.key_id' => null, 'services.razorpay.key_secret' => null, 'services.razorpay.webhook_secret' => null]);
        }

        $this->settings->set($values);
        $this->audit($request, array_keys($values));

        return back()->with('success', __('Payment settings saved.'));
    }

    public function testPayments(RazorpayGateway $gateway): RedirectResponse
    {
        try {
            $works = $gateway->keysWork();
        } catch (Throwable $exception) {
            Log::warning('Razorpay key test failed', ['error' => $exception->getMessage()]);
            $works = false;
        }

        return $works
            ? back()->with('success', __('Razorpay accepted these keys.'))
            : back()->withErrors(['razorpay_key_id' => __('Razorpay did not accept these keys. Check them and try again.')]);
    }

    public function email(): View
    {
        return view('admin.email', [
            'values' => collect(['mail_host', 'mail_port', 'mail_security', 'mail_username', 'mail_from_address', 'mail_from_name'])->mapWithKeys(fn ($key) => [$key => $this->settings->get($key)]),
            'hasPassword' => $this->settings->has('mail_password'),
            'configured' => config('mail.default') !== 'log',
        ]);
    }

    public function updateEmail(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mail_host' => ['nullable', 'string', 'max:190'],
            'mail_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail_security' => ['nullable', Rule::in(['tls', 'ssl'])],
            'mail_username' => ['nullable', 'string', 'max:190'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'mail_from_address' => ['nullable', 'email', 'max:190'],
            'mail_from_name' => ['nullable', 'string', 'max:120'],
        ]);

        $values = $this->withoutBlank($data, ['mail_password']);
        $this->settings->set($values);
        $this->audit($request, array_keys($values));

        return back()->with('success', __('Email settings saved.'));
    }

    public function testEmail(Request $request): RedirectResponse
    {
        try {
            Mail::raw('This is a test email from your Nirmaan360 admin settings.', fn ($message) => $message
                ->to($request->user()->email)
                ->subject('Nirmaan360 test email'));
        } catch (Throwable $exception) {
            Log::warning('Test email failed', ['error' => $exception->getMessage()]);

            return back()->withErrors(['mail_host' => __('The email could not be sent. Check the settings and try again.')]);
        }

        return back()->with('success', __('Test email sent to :email.', ['email' => $request->user()->email]));
    }

    public function website(): View
    {
        return view('admin.website', [
            'values' => collect(self::WEBSITE_FIELDS)->mapWithKeys(fn ($key) => [$key => $this->settings->get($key)]),
            'sections' => self::SECTIONS,
            'hasLogo' => $this->settings->has('logo_path'),
        ]);
    }

    public function updateWebsite(Request $request): RedirectResponse
    {
        $color = ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'];
        $url = ['nullable', 'url:http,https', 'max:255'];

        $data = $request->validate([
            'site_name' => ['nullable', 'string', 'max:60'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'info_email' => ['nullable', 'email', 'max:190'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'contact_address' => ['nullable', 'string', 'max:300'],
            'social_facebook' => $url,
            'social_instagram' => $url,
            'social_linkedin' => $url,
            'social_youtube' => $url,
            'social_x' => $url,
            'announcement' => ['nullable', 'string', 'max:200'],
            'announcement_link' => $url,
            'privacy_text' => ['nullable', 'string', 'max:50000'],
            'terms_text' => ['nullable', 'string', 'max:50000'],
            'refund_text' => ['nullable', 'string', 'max:50000'],
            'color_primary' => $color,
            'color_primary_dark' => $color,
            'color_accent' => $color,
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
        ]);
        unset($data['logo']);

        foreach (array_keys(self::SECTIONS) as $section) {
            $data['section_'.$section] = $request->boolean('sections.'.$section) ? '1' : '0';
        }

        if ($request->hasFile('logo')) {
            $this->deleteLogo();
            $data['logo_path'] = $request->file('logo')->store('platform');
            // Its size lets the page keep the logo's space before the picture loads, so the menu does not jump.
            [$data['logo_width'], $data['logo_height']] = getimagesize($request->file('logo')->getRealPath()) ?: [null, null];
        } elseif ($request->boolean('remove_logo')) {
            $this->deleteLogo();
            $data['logo_path'] = $data['logo_width'] = $data['logo_height'] = null;
        }

        // Clearing a field should bring back the built-in brand name.
        if (blank($data['site_name'] ?? null)) {
            config(['buildos.brand' => 'Nirmaan360']);
        }

        $this->settings->set($data);
        $this->audit($request, array_keys($data));

        return back()->with('success', __('Website settings saved.'));
    }

    public function texts(Request $request): View
    {
        [$page, $locale] = $this->pageAndLocale($request);
        $translator = app('translator');

        $saved = SiteText::where('locale', $locale)->pluck('value', 'key_hash');

        $rows = array_map(fn (string $key) => [
            'hash' => sha1($key),
            'key' => $key,
            'default' => $translator->defaultLine($key, $locale),
            'saved' => $saved[sha1($key)] ?? null,
        ], $this->pageKeys($page));

        return view('admin.texts', ['rows' => $rows, 'page' => $page, 'locale' => $locale, 'pages' => self::PAGES]);
    }

    public function updateTexts(Request $request): RedirectResponse
    {
        [$page, $locale] = $this->pageAndLocale($request);
        $input = $request->validate(['texts' => ['array'], 'texts.*' => ['nullable', 'string', 'max:5000']])['texts'] ?? [];

        foreach ($this->pageKeys($page) as $key) {
            $hash = sha1($key);

            if (! array_key_exists($hash, $input)) {
                continue;
            }

            if (blank($input[$hash])) {
                SiteText::where(['locale' => $locale, 'key_hash' => $hash])->delete();
            } else {
                SiteText::updateOrCreate(['locale' => $locale, 'key_hash' => $hash], ['source' => $key, 'value' => $input[$hash]]);
            }
        }

        app('translator')->forgetOverrides();
        $this->audit($request, ['site_texts:'.$page.':'.$locale]);

        return back()->with('success', __('Website text saved.'));
    }

    public function admins(Request $request): View
    {
        return view('admin.admins', ['admins' => User::with('organization')->where('is_platform_admin', true)->orderBy('name')->get()]);
    }

    public function addAdmin(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'exists:users,email']]);

        User::where('email', $data['email'])->firstOrFail()->forceFill(['is_platform_admin' => true])->save();
        $this->audit($request, ['platform_admin:add']);

        return back()->with('success', __('Admin added.'));
    }

    public function removeAdmin(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->withErrors(['email' => __('You cannot remove yourself.')]);
        }

        $user->forceFill(['is_platform_admin' => false])->save();
        $this->audit($request, ['platform_admin:remove']);

        return back()->with('success', __('Admin removed.'));
    }

    /** @return array{0:string,1:string} */
    private function pageAndLocale(Request $request): array
    {
        $page = (string) $request->input('page', 'home');
        $locale = (string) $request->input('locale', 'en');

        return [
            array_key_exists($page, self::PAGES) ? $page : 'home',
            array_key_exists($locale, config('buildos.locales')) ? $locale : 'en',
        ];
    }

    /** Renders a page and notes every piece of text it uses, so nothing has to be listed by hand. */
    private function pageKeys(string $page): array
    {
        $translator = app('translator');
        $translator->startRecording();

        try {
            match ($page) {
                'home' => view('public.home', ['plans' => config('buildos.plans')])->render(),
                'pricing' => view('public.pricing', ['plans' => config('buildos.plans'), 'trialDays' => config('buildos.trial_days')])->render(),
                'ai' => view('public.ai')->render(),
                'contact' => view('public.contact', ['type' => 'sales'])->render(),
                'calculators' => view('public.calculators')->render(),
                'signup' => [view('auth.login', ['otpEnabled' => false])->render(), view('auth.register', ['plan' => 'contractor'])->render()],
            };
        } finally {
            $keys = $translator->stopRecording();
        }

        // These are only drawn for signed-out visitors, and an admin is signed in.
        return array_values(array_unique($page === 'signup' ? $keys : [...$keys, 'Sign in', 'Start free']));
    }

    private function withoutBlank(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (blank($data[$key] ?? null)) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    private function deleteLogo(): void
    {
        $path = $this->settings->get('logo_path');

        if ($path) {
            Storage::delete($path);
        }
    }

    /** Records which settings changed, never their values: some are secrets. */
    private function audit(Request $request, array $keys): void
    {
        AuditLog::record('platform.settings_changed', $request->user(), null, ['settings' => $keys]);
    }
}
