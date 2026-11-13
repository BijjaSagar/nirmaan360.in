<?php

namespace App\Http\Controllers\SiteDiary;

use App\Http\Controllers\Controller;
use App\Models\Hindrance;
use App\Models\Project;
use App\Services\ContractTerms\TermText;
use App\Services\DocumentReading\ReadRefused;
use App\Services\DocumentReading\SpendRefused;
use App\Services\SiteDiary\HindranceRegister;
use App\Services\SiteDiary\Hindrances;
use App\Services\SiteDiary\HindranceSuggestions;
use App\Services\SiteDiary\NoticeDeadlines;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

class HindranceController extends Controller
{
    use AnswersAiRefusals;

    public function __construct(private Hindrances $hindrances) {}

    public function index(Request $request, Project $project, HindranceRegister $register, NoticeDeadlines $deadlines, TermText $text, HindranceSuggestions $suggestions): View
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

        $shown = ['from' => self::day($request->query('from')), 'to' => self::day($request->query('to'))];
        // Reports ticked on the form a moment ago stay listed when it comes back: after a refused save, or after showing other dates.
        $kept = array_values(array_filter(array_map(fn ($id) => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]), (array) $request->old('dpr_ids', []))));

        $seesTerms = $user->can('contract_terms.view') && Money::visible($user)
            && $user->organization->allowsFeature('billing') && Route::has('contract-terms.index');

        return view('modules.site_diary.index', [
            'project' => $project,
            'entries' => $entries,
            'total' => $register->total($entries->where('counts', true)->pluck('hindrance'), $today),
            'noticeTerms' => $deadlines->entered($project),
            'candidates' => $canMake ? $register->candidates($project, $shown['from'], $shown['to'], $editing ?? $correcting, $kept) : null,
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
            // "Suggest groups": whether it is offered to this person, and how the latest request ended.
            'grouping' => $suggestions->panel($project, $user),
        ]);
    }

    /** Ask the AI to group the hindrance notes of daily reports into entries waiting to be confirmed. */
    public function suggest(Request $request, Project $project, HindranceSuggestions $suggestions): JsonResponse|RedirectResponse
    {
        $data = $request->validate($suggestions->rules());

        try {
            $suggestion = $suggestions->start($project, $data, $request->user());
        } catch (SpendRefused|ReadRefused $refused) {
            return $this->refusal($request, $refused);
        }

        if ($request->expectsJson()) {
            return response()->json(['id' => $suggestion->id, 'status' => $suggestion->status], 202);
        }

        return redirect()->to(route('site-diary.hindrances.index', $project).'#grouping')
            ->with('success', __('The AI is grouping the notes. What it proposes appears under Waiting to be confirmed, and nothing counts until you confirm it.'));
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        if ($request->has('show_reports')) {
            return $this->showReports($request, $project, null);
        }

        $hindrance = $this->hindrances->create($project, $request->validate($this->hindrances->rules($project, $request->all())), $request->user());

        return redirect()->to(route('site-diary.hindrances.index', $project).'#hindrance-'.$hindrance->id)
            ->with('success', __('Entry saved in the register.'));
    }

    public function update(Request $request, Hindrance $hindrance): RedirectResponse
    {
        $project = $this->projectOf($hindrance);

        if ($request->has('show_reports')) {
            return $this->showReports($request, $project, $hindrance);
        }

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

    /**
     * The form's "Show" for daily reports of other dates. Nothing is saved: the register opens again with
     * the reports of those dates listed, and with everything the person ticked and typed still in the form.
     */
    private function showReports(Request $request, Project $project, ?Hindrance $editing): RedirectResponse
    {
        $correcting = $request->input('supersedes_id');

        $query = array_filter([
            'edit' => $editing?->id,
            'correct' => $editing === null && is_string($correcting) && ctype_digit($correcting) ? $correcting : null,
            'from' => self::day($request->input('from')),
            'to' => self::day($request->input('to')),
        ]);

        return redirect()->to(route('site-diary.hindrances.index', [$project, ...$query]).'#entry-form')
            ->withInput($request->except(['_token', '_method', 'from', 'to', 'show_reports']));
    }

    /** A day as the date fields send it, or null. */
    private static function day(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
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
