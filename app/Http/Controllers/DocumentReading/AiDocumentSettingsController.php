<?php

namespace App\Http\Controllers\DocumentReading;

use App\Http\Controllers\Controller;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\ReaderSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The company's own switch for sending documents to the AI service, and its spending limits. Owners and admins only. */
class AiDocumentSettingsController extends Controller
{
    /** An amount of credits as a person types it: up to six digits, and up to four after the point. */
    private const CREDITS = 'regex:/^\d{1,6}(\.\d{1,4})?$/';

    public function edit(Request $request, ReaderSettings $settings): View
    {
        $organization = $request->user()->organization;
        $offered = $settings->offered($organization);
        $row = $settings->of($organization);
        $locale = app()->getLocale();

        return view('modules.document_reading.settings', [
            'offered' => $offered,
            'settings' => $row,
            'consent' => $settings->consent($organization),
            'sentences' => $offered ? $settings->sentences($locale) : [],
            'noticeLocale' => $locale,
            'noticeHash' => $offered ? AiDocumentConsent::hash($settings->notice($locale)) : null,
            'consentedBy' => $row?->consented_by ? $organization->users()->find($row->consented_by) : null,
            'limits' => $settings->limitsOffered($organization) ? $settings->limits($organization) : null,
            'showReadMaximum' => $settings->hasReadKinds(),
        ]);
    }

    public function update(Request $request, ReaderSettings $settings): RedirectResponse
    {
        $user = $request->user();
        $organization = $user->organization;
        $action = $request->validate(['action' => ['required', Rule::in(['on', 'off', 'limits'])]])['action'];

        if ($action === 'off') {
            $cancelled = $settings->turnOff($organization, $user);

            return redirect()->route('ai-documents.edit')->with('success', $cancelled > 0
                ? __('The readers are switched off and send nothing new. Documents that were still being read and are now cancelled: :count.', ['count' => $cancelled])
                : __('The readers are switched off and send nothing new.'));
        }

        if ($action === 'on') {
            $data = $request->validate([
                'accept' => ['accepted'],
                'notice_locale' => ['required', Rule::in(array_keys(config('buildos.locales')))],
                'notice_hash' => ['required', 'string', 'size:64'],
            ], ['accept.accepted' => __('Tick the box to confirm that you have read the notice.')]);

            $settings->turnOn($organization, $user, $data['notice_locale'], $data['notice_hash']);

            return redirect()->route('ai-documents.edit')->with('success', __('The readers are switched on for your company.'));
        }

        $features = $settings->features();
        // Only the features this deployment has may be given a budget from the form.
        $only = fn (array $keys) => $keys === [] ? ['nullable', 'array', 'max:0'] : ['nullable', 'array:'.implode(',', $keys)];
        $platformMaximum = config('modules.document_reading.max_credits_per_read');

        $data = $request->validate([
            'max_credits_per_read' => ['nullable', 'numeric', self::CREDITS, 'gt:0', 'lte:'.$platformMaximum],
            'budgets' => ['nullable', 'array:user,company'],
            'budgets.user' => $only($features),
            'budgets.company' => $only([...$features, 'total']),
            'budgets.user.*' => ['nullable', 'numeric', self::CREDITS],
            'budgets.company.*' => ['nullable', 'numeric', self::CREDITS],
        ], [
            'numeric' => __('Enter a number of credits, such as 50 or 12.5.'),
            'regex' => __('Enter a number of credits, such as 50 or 12.5.'),
            // Zero is a number, so it is told why it is refused here, and where a feature is stopped instead.
            'max_credits_per_read.gt' => __('The most one document may use must be more than 0. To stop a feature, enter 0 as its daily budget.'),
            'max_credits_per_read.lte' => __('The most one document may use cannot be above :max credits.', ['max' => $platformMaximum]),
        ]);

        // A figure left empty goes back to the default. Without the per-read field on the form, the saved figure stays.
        $settings->saveLimits($organization, $user, $data);

        return redirect()->route('ai-documents.edit')->with('success', __('The limits are saved.'));
    }
}
