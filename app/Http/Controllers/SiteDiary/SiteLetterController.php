<?php

namespace App\Http\Controllers\SiteDiary;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\SiteLetter;
use App\Services\SiteDiary\LetterFacts;
use App\Services\SiteDiary\LetterLayout;
use App\Services\SiteDiary\SiteLetters;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SiteLetterController extends Controller
{
    public function __construct(private SiteLetters $letters, private LetterFacts $facts) {}

    public function index(Request $request, Project $project): View
    {
        $user = $request->user();
        $types = $this->letters->typesFor($project, $user);
        $offersBills = in_array(SiteLetter::PAYMENT_FOLLOWUP, $types, true);

        return view('modules.site_diary.letters', [
            'project' => $project,
            'letters' => SiteLetter::query()->where('project_id', $project->id)->with('finalisedBy')->latest('id')->paginate(25),
            'types' => $types,
            'entries' => $this->facts->citable($project, SiteLetter::EOT_REQUEST, today()),
            // Amounts are read only for someone who may see them.
            'bills' => $offersBills ? $this->facts->unpaidBills($project) : collect(),
            'seesMoney' => Money::visible($user),
            'today' => today(),
            ...$this->names(),
            // The languages a new letter may be prepared in. Letters already prepared keep theirs, named from the full list.
            'offered' => $this->letters->languages(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        // Before anything about a bill is checked or said: a payment follow-up is not for someone who may not see money.
        $this->mustRead(is_string($request->input('type')) ? $request->input('type') : '', $request);
        $letter = $this->letters->create($project, $request->validate($this->letters->rules($project, $request->all())), $request->user());

        return redirect()->route('site-diary.letters.show', $letter)
            ->with('success', __('Draft prepared from your records. Check every figure before you finalise it.'));
    }

    public function show(Request $request, SiteLetter $letter): View
    {
        $project = $this->projectOf($letter);
        $this->mustRead($letter->type, $request);
        $draft = ! $letter->isFinalised();

        return view('modules.site_diary.letter', [
            'project' => $project,
            'letter' => $letter->load('finalisedBy'),
            'review' => $this->letters->review($letter),
            'chosen' => $this->letters->chosenOf($letter),
            // What the draft may cite instead, should the person choose again.
            'entries' => $draft && ! $letter->carriesMoney() ? $this->facts->citable($project, $letter->type, today()) : collect(),
            'bills' => $draft && $letter->carriesMoney() ? $this->facts->unpaidBills($project) : collect(),
            'today' => today(),
            ...$this->names(),
        ]);
    }

    public function update(Request $request, SiteLetter $letter): RedirectResponse
    {
        $project = $this->projectOf($letter);
        $this->mustRead($letter->type, $request);
        $this->letters->update($letter, $request->validate($this->letters->rules($project, $request->all(), $letter)), $request->user());

        return redirect()->route('site-diary.letters.show', $letter)
            ->with('success', __('Draft saved and prepared again from your records. Check every figure before you finalise it.'));
    }

    public function finalise(Request $request, SiteLetter $letter): RedirectResponse
    {
        $this->projectOf($letter);
        $this->mustRead($letter->type, $request);

        $sent = $request->validate([
            // The draft as this page showed it, so ticks given on an earlier draft do not count for a later one.
            'seen' => ['required', 'string', 'size:64'],
            'number_ticks' => ['nullable', 'array', 'max:2000'],
            'number_ticks.*' => ['string', 'max:255'],
        ]);

        $this->letters->finalise($letter, $sent['number_ticks'] ?? [], $request->user(), $sent['seen']);

        return redirect()->route('site-diary.letters.show', $letter)
            ->with('success', __('Letter finalised. It can no longer be changed. Print it or copy it: the app sends nothing.'));
    }

    /** A payment follow-up is nothing but money, so it is refused whole, not shown with gaps. */
    private function mustRead(string $type, Request $request): void
    {
        abort_unless($this->letters->mayRead($type, $request->user()), 403, Money::refusal());
    }

    /** A letter outlives its project only as a record: once the project is removed, it cannot be opened or changed. */
    private function projectOf(SiteLetter $letter): Project
    {
        return $letter->project ?? abort(404);
    }

    /** @return array<string, mixed> The names of the choices on both screens. */
    private function names(): array
    {
        return [
            'typeNames' => config('modules.site_diary.letter_types'),
            'statusNames' => config('modules.site_diary.letter_statuses'),
            'languages' => array_intersect_key(config('buildos.locales'), array_flip(['en', 'hi', 'mr'])),
            'categories' => config('modules.site_diary.categories'),
            'caution' => LetterLayout::CAUTION,
        ];
    }
}
