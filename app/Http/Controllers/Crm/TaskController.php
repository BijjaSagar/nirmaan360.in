<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmTask;
use App\Services\Crm\Lookups;
use App\Services\Crm\Subjects;
use App\Services\Crm\TaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TaskController extends Controller
{
    public function __construct(private TaskService $service, private Lookups $lookups) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $request->only(['scope', 'status', 'when', 'assignee']);
        $tasks = $this->service->query($user->organization_id, $user, $filters)->paginate(40)->withQueryString();

        $subjects = $tasks->getCollection()->mapWithKeys(function (CrmTask $task) use ($user) {
            $subject = Subjects::find($task->subject_type, $task->subject_id, $user->organization_id);

            return [$task->id => $subject ? ['title' => Subjects::title($subject), 'url' => Subjects::url($task->subject_type, $task->subject_id)] : null];
        });

        return view('modules.crm.tasks.index', [
            'filters' => $filters,
            'tasks' => $tasks,
            'subjects' => $subjects,
            'counts' => $this->service->counts($user->organization_id, $user),
            'owners' => $this->lookups->users($user->organization_id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->service->create($request->validate($this->service->rules()), $request->user());

        return back()->with('success', __('Follow-up added.'));
    }

    public function update(Request $request, CrmTask $task): RedirectResponse
    {
        $this->service->update($task, $request->validate($this->service->rules()));

        return back()->with('success', __('Follow-up updated.'));
    }

    public function complete(Request $request, CrmTask $task): RedirectResponse
    {
        $this->service->complete($task, $request->user());

        return back()->with('success', __('Follow-up done.'));
    }

    public function reopen(CrmTask $task): RedirectResponse
    {
        $this->service->reopen($task);

        return back()->with('success', __('Follow-up reopened.'));
    }

    public function destroy(CrmTask $task): RedirectResponse
    {
        $this->service->delete($task);

        return back()->with('success', __('Follow-up deleted.'));
    }
}
