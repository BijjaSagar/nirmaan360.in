<?php

namespace App\Http\Controllers\Qhs;

use App\Http\Controllers\Controller;
use App\Models\ChecklistTemplate;
use App\Services\Qhs\ChecklistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** The company's reusable checklist templates. */
class ChecklistController extends Controller
{
    public function __construct(private ChecklistService $service) {}

    public function index(): View
    {
        return view('modules.qhs.checklists.index', [
            'templates' => ChecklistTemplate::withCount('inspections')->orderByDesc('is_active')->orderBy('name')->get(),
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->service->createTemplate($this->validated($request), $request->user());

        return back()->with('success', __('Checklist saved.'));
    }

    public function update(Request $request, ChecklistTemplate $template): RedirectResponse
    {
        $this->service->updateTemplate($template, $this->validated($request, $template));

        return back()->with('success', __('Checklist updated.'));
    }

    /** Add the standard pour card, shuttering, reinforcement, PPE and safety checklists. */
    public function standard(Request $request): RedirectResponse
    {
        $added = $this->service->installStandard($request->user());

        return back()->with('success', $added > 0
            ? __(':count standard checklists added.', ['count' => $added])
            : __('All the standard checklists are already there.'));
    }

    /** The form sends one item per line. The service takes a list. */
    private function validated(Request $request, ?ChecklistTemplate $template = null): array
    {
        $request->merge(['items' => ChecklistService::parseItems($request->input('items_text'))]);

        return $request->validate($this->service->templateRules($request->user(), $template));
    }
}
