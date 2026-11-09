<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\Indent;
use App\Models\Party;
use App\Models\Project;
use App\Services\Procurement\IndentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IndentController extends Controller
{
    use PicksMaterials;

    public function __construct(private IndentService $service) {}

    public function index(Project $project): View
    {
        return view('modules.procurement.indents.index', [
            'project' => $project,
            'indents' => Indent::with('store', 'creator')->withCount('items')->where('project_id', $project->id)->latest('id')->paginate(25),
        ]);
    }

    public function create(Project $project): View
    {
        return view('modules.procurement.indents.form', $this->formData($project));
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $indent = $this->service->create($project, $request->validate($this->service->rules($project)), $request->user());

        return redirect()->route('procurement.indents.show', $indent)->with('success', __('Indent saved as draft.'));
    }

    public function show(Indent $indent): View
    {
        $indent->load('project', 'store', 'creator', 'items.material', 'items.unit', 'items.boqItem', 'approvals.steps.user', 'rfqs');

        return view('modules.procurement.indents.show', [
            'indent' => $indent,
            'project' => $indent->project,
            'vendors' => Party::where('type', 'vendor')->orderBy('name')->get(),
        ]);
    }

    public function edit(Indent $indent): View
    {
        abort_unless($indent->isEditable(), 409, __('Only draft or returned indents can be edited.'));

        return view('modules.procurement.indents.form', [
            ...$this->formData($indent->project),
            'indent' => $indent->load('items'),
        ]);
    }

    public function update(Request $request, Indent $indent): RedirectResponse
    {
        $this->service->update($indent, $request->validate($this->service->rules($indent->project)));

        return redirect()->route('procurement.indents.show', $indent)->with('success', __('Indent saved.'));
    }

    public function submit(Request $request, Indent $indent): RedirectResponse
    {
        $this->service->submit($indent, $request->user());

        return back()->with('success', __('Indent sent for approval.'));
    }

    /** @return array<string, mixed> */
    private function formData(Project $project): array
    {
        return [
            'project' => $project,
            'indent' => null,
            'stores' => $this->projectStores($project),
            'materialOptions' => $this->materialOptions(),
            'boqItems' => $this->boqOptions($project),
        ];
    }
}
