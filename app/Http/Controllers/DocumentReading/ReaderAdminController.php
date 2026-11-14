<?php

namespace App\Http\Controllers\DocumentReading;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\DocumentReading\ReaderControl;
use App\Services\DocumentReading\ReaderDashboard;
use App\Services\DocumentReading\ReaderFixtures;
use App\Services\DocumentReading\ReaderTrials;
use App\Services\DocumentReading\ReadQueues;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The platform admin's page for the AI readers: the health of the worker and the scheduler, the
 * spending limits, the release switch, what each company uses, and the dry runs. Platform staff
 * only; everyone else gets a 404 from the route's middleware.
 */
class ReaderAdminController extends Controller
{
    public function index(Request $request, ReaderDashboard $dashboard, ReaderControl $control, ReaderTrials $trials): View
    {
        $search = $request->query('company');

        return view('modules.document_reading.admin.index', [
            'health' => $dashboard->health(),
            'spend' => $dashboard->spend(),
            'saved' => $control->saved(),
            'release' => $control->releaseState(),
            'companies' => $dashboard->companies(is_string($search) ? $search : null),
            'companySearch' => is_string($search) ? $search : '',
            'readers' => $dashboard->readers(),
            'trialFigures' => $dashboard->trialFigures(),
            'trialPagesRead' => $dashboard->trialPagesRead(),
            'recentTrials' => $dashboard->recentTrials(),
            'targets' => $trials->targets(),
            'models' => array_keys(config('modules.agents.pricing', [])),
            'fixtures' => collect(ReaderFixtures::names())->mapWithKeys(fn (string $name) => [$name => route('ai-reader.admin.fixtures', ['name' => $name], false)])->all(),
        ]);
    }

    public function update(Request $request, ReaderControl $control): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', Rule::in(['limits', 'queues', 'release', 'withdraw'])]])['action'];
        $admin = $request->user();

        if ($action === 'limits') {
            $data = $request->validate([
                'monthly_cap' => ['nullable', 'string', 'max:20'],
                'daily_limit' => ['nullable', 'string', 'max:20'],
                'month_share' => ['nullable', 'string', 'max:20'],
                'trial_allowance' => ['nullable', 'string', 'max:20'],
            ]);

            $control->saveLimits($admin, $data);

            return back()->with('success', __('Spending limits saved.'));
        }

        if ($action === 'queues') {
            $data = $request->validate([
                'queues' => ['nullable', 'array:'.implode(',', ReadQueues::LANES)],
                'queues.*' => ['nullable', 'string', 'max:60'],
            ]);

            $control->saveQueues($admin, $data['queues'] ?? []);

            return back()->with('success', __('Queue names saved.'));
        }

        if ($action === 'release') {
            $data = $request->validate([
                'dry_run' => ['nullable', 'array', 'max:40'],
                'dry_run.*' => ['string', 'max:24'],
            ]);

            $control->release($admin, $request->boolean('alerts_set'), array_values($data['dry_run'] ?? []));

            return back()->with('success', __('The readers are now offered to companies.'));
        }

        $control->withdraw($admin);

        return back()->with('success', __('The readers are no longer offered to companies. Reads that are running pause before their next page.'));
    }

    public function company(Request $request, Organization $organization, ReaderControl $control): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', Rule::in(['lift_quota', 'restore_quota', 'block', 'unblock', 'lift_brake'])]])['action'];

        $control->company($organization, $action, $request->user());

        return back()->with('success', match ($action) {
            'lift_quota' => __('The trial allowance no longer limits this company.'),
            'restore_quota' => __('The trial allowance applies to this company again.'),
            'block' => __('The readers are switched off for this company.'),
            'unblock' => __('The readers are available to this company again.'),
            default => __('Earlier unusable answers no longer count against this company.'),
        });
    }

    public function clearStop(Request $request, ReaderControl $control): RedirectResponse
    {
        $control->clearProviderStop($request->user());

        return back()->with('success', __('The stop is cleared. Pages are sent again when a read is continued.'));
    }

    /** One of the small test PDFs the browser self-check draws. Their content is invented; they are served to platform admins only. */
    public function fixture(string $name): BinaryFileResponse
    {
        $path = ReaderFixtures::path($name);

        abort_if($path === null, 404);

        return response()->file($path, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }
}
