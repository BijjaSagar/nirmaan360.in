<?php

namespace App\Http\Controllers\SiteDiary;

use App\Http\Controllers\Controller;
use App\Models\Hindrance;
use App\Models\Project;
use App\Services\ContractTerms\TermText;
use App\Services\SiteDiary\HindranceRegister;
use App\Services\SiteDiary\Hindrances;
use App\Services\SiteDiary\NoticeDeadlines;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

class HindranceController extends Controller
{
    public function __construct(private Hindrances $hindrances) {}

    public function index(Request $request, Project $project, HindranceRegister $register, NoticeDeadlines $deadlines, TermText $text): View
    {
        $user = $request->user();
        $today = today();
        $entries = $register->entries($project, $today);

        // Making an entry copies what daily reports say, so it also takes the right to open them.
        $canManage = $user->can('site_diary.manage');
        $canMake = $canManage && $user->can('dpr.view');

        // One entry at a time is opened in the form: to change it, or to correct it when a letter already cites it.
        $editing = $canMake ? $this->entryOf($project, $request->query('edit'), locked: false) : null;
        $correcting = $canMake && $editing === null ? $this->entryOf($project, $request->query('correct'), locked: true) : null;

        $day = fn (string $key) => is_string($request->query($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query($key)) === 1 ? $request->query($key) : null;
        $shown = ['from' => $day('from'), 'to' => $day('to')];

        $seesTerms = $user->can('contract_terms.view') && Money::visible($user)
            && $user->organization->allowsFeature('billing') && Route::has('contract-terms.index');

        return view('modules.site_diary.index', [
            'project' => $project,
            'entries' => $entries,
            'total' => $register->total($entries->where('counts', true)->pluck('hindrance'), $today),
            'noticeTerms' => $deadlines->entered($project),
            'candidates' => $canMake ? $register->candidates($project, $shown['from'], $shown['to'], $editing ?? $correcting) : null,
            'shown' => $shown,
            'editing' => $editing,
            'correcting' => $correcting,
            'sites' => $project->sites()->orderBy('name')->get(['id', 'name']),
            'canManage' => $canManage,
            'canMake' => $canMake,
            'text' => $text,
            'today' => $today,
            'categories' => config('modules.site_diary.categories'),
            'statuses' => config('modules.site_diary.statuses'),
            'termOptions' => config('modules.contract_terms'),
            // The clause a person typed for a term, and the page it is entered on, go to people who may open that page.
            'termsLink' => $seesTerms ? route('contract-terms.index', $project) : null,
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $hindrance = $this->hindrances->create($project, $request->validate($this->hindrances->rules($project, $request->all())), $request->user());

        return redirect()->to(route('site-diary.hindrances.index', $project).'#hindrance-'.$hindrance->id)
            ->with('success', __('Entry saved in the register.'));
    }

    public function update(Request $request, Hindrance $hindrance): RedirectResponse
    {
        $project = $this->projectOf($hindrance);
        $this->hindrances->update($hindrance, $request->validate($this->hindrances->rules($project, $request->all(), $hindrance)), $request->user());

        return redirect()->to(route('site-diary.hindrances.index', $project).'#hindrance-'.$hindrance->id)
            ->with('success', __('Entry changed.'));
    }

    public function confirm(Request $request, Hindrance $hindrance): RedirectResponse
    {
        $this->projectOf($hindrance);
        $this->hindrances->confirm($hindrance, $request->user());

        return back()->with('success', __('Entry confirmed.'));
    }

    public function dismiss(Hindrance $hindrance): RedirectResponse
    {
        $this->projectOf($hindrance);
        $this->hindrances->dismiss($hindrance);

        return back()->with('success', __('Entry dismissed. It stays in the register and is no longer counted.'));
    }

    /** An entry outlives its project only as a record: once the project is removed, it cannot be changed. */
    private function projectOf(Hindrance $hindrance): Project
    {
        return $hindrance->project ?? abort(404);
    }

    /** The entry named in the address, when it belongs to this project and is, or is not, locked as asked. */
    private function entryOf(Project $project, mixed $id, bool $locked): ?Hindrance
    {
        if (! is_string($id) || ! ctype_digit($id)) {
            return null;
        }

        $entry = Hindrance::with('sources')->where('project_id', $project->id)->find((int) $id);

        return $entry !== null && $entry->isLocked() === $locked ? $entry : null;
    }
}
