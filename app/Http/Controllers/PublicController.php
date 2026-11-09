<?php

namespace App\Http\Controllers;

use App\Models\ContactRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PublicController extends Controller
{
    private const CONTACT_TYPES = ['sales', 'ai_early_access', 'support'];

    public function home(): View
    {
        return view('public.home', ['plans' => config('buildos.plans')]);
    }

    public function calculators(): View
    {
        return view('public.calculators');
    }

    public function pricing(): View
    {
        return view('public.pricing', [
            'plans' => config('buildos.plans'),
            'gstPct' => config('buildos.subscription_gst_pct'),
            'trialDays' => config('buildos.trial_days'),
        ]);
    }

    public function ai(): View
    {
        return view('public.ai');
    }

    public function contact(Request $request): View
    {
        $type = $request->query('type');

        return view('public.contact', ['type' => in_array($type, self::CONTACT_TYPES, true) ? $type : 'sales']);
    }

    public function storeContact(Request $request): RedirectResponse
    {
        // Bots fill the hidden "website" field. They get the normal thank-you and nothing is saved.
        if (filled($request->input('website'))) {
            return back()->with('success', __('Thanks. We have your message and will get back to you.'));
        }

        ContactRequest::create($request->validate([
            'type' => ['required', Rule::in(self::CONTACT_TYPES)],
            'name' => ['required', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:20'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]));

        return back()->with('success', __('Thanks. We have your message and will get back to you.'));
    }

    public function privacy(): View
    {
        return view('public.privacy');
    }

    public function terms(): View
    {
        return view('public.terms');
    }
}
