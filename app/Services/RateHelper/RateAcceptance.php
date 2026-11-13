<?php

namespace App\Services\RateHelper;

use App\Models\BoqItem;
use App\Models\RateSuggestionLine;
use App\Models\RateSuggestionRun;
use App\Models\User;
use App\Services\BoqService;
use App\Services\ProjectSetup\BoqBudget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Accepting one rate for one line, or skipping the line. There is no way to accept several at once.
 *
 * The rate that is written is read here, from the source row, as the person who is accepting. What
 * the list showed earlier is not trusted: a row that has gone, moved out of this person's reach or
 * changed its unit since then is refused, and so is a row whose rate or description is no longer
 * what the page showed. The form sends back the figure's fingerprint for that check only; no
 * number from the browser is ever written.
 */
class RateAcceptance
{
    public function __construct(
        private RateSources $sources,
        private RateReview $review,
        private BoqService $boq,
        private BoqBudget $budget,
    ) {}

    public function rules(): array
    {
        return [
            'candidate' => ['required', 'string', 'max:12'],
            'mode' => ['required', Rule::in(RateSuggestionLine::MODES)],
            'checked_warnings' => ['nullable', 'boolean'],
            // RateFigure::fingerprint() of what the page showed. A missing one counts as changed.
            'shown' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @param  string|null  $shown  The fingerprint of the figure on the person's screen when they pressed Accept. */
    public function accept(User $viewer, RateSuggestionRun $run, RateSuggestionLine $line, string $ref, string $mode, bool $checkedWarnings, ?string $shown): RateSuggestionLine
    {
        if ($mode === RateSuggestionLine::NOTE_CURRENT_RATE && ! $this->review->canNote($viewer)) {
            throw new AuthorizationException(__('Noting a current rate needs access to the project budget.'));
        }

        return DB::transaction(function () use ($viewer, $run, $line, $ref, $mode, $checkedWarnings, $shown) {
            $line = RateSuggestionLine::query()->where('run_id', $run->id)->lockForUpdate()->findOrFail($line->id);

            if ($line->isAccepted()) {
                // The same press arriving twice changes nothing.
                if ($line->accepted_ref === $ref && $line->accepted_mode === $mode) {
                    return $line;
                }

                throw ValidationException::withMessages(['candidate' => __('A rate was already accepted for this line.')]);
            }

            $listed = $line->candidate($ref);

            if ($listed === null) {
                throw ValidationException::withMessages(['candidate' => __('This rate is not on the list for this line.')]);
            }

            $item = BoqItem::query()->where('project_id', $run->project_id)->find($line->boq_item_id);

            if ($item === null) {
                throw ValidationException::withMessages(['candidate' => __('This BOQ line is no longer in the BOQ.')]);
            }

            $sources = $this->sources->open();
            $entry = $this->review->candidate($sources, $viewer, $item, $listed, (int) config('modules.rate_helper.stale_after_days', 180));

            if ($entry === null) {
                throw ValidationException::withMessages(['candidate' => __('This rate is no longer available to you. It may have been removed, changed or moved out of your projects.')]);
            }

            $figure = $entry['figure'];

            if (! $figure->acceptable) {
                throw ValidationException::withMessages(['candidate' => __('This figure is shown for reference only and cannot be accepted as a rate.')]);
            }

            // The person approves the figure they saw. If someone edited the row since, they look again.
            if ($shown === null || ! hash_equals($figure->fingerprint(), $shown)) {
                throw ValidationException::withMessages(['candidate' => __('This rate or its description changed after you opened the page. Check what is shown now before you accept.')]);
            }

            if ($entry['warnings'] !== [] && ! $checkedWarnings) {
                throw ValidationException::withMessages(['checked_warnings' => __('Tick "I checked grade, mix and thickness" before you accept this rate.')]);
            }

            $words = [
                'source' => __($entry['source_label']),
                'item' => Str::limit($figure->description, 200),
                'date' => $figure->date?->format('d M Y') ?? __('no date'),
            ];

            if ($mode === RateSuggestionLine::SET_BOQ_RATE) {
                $this->boq->update($item, ['rate' => $figure->rate], __('Rate taken from :source: :item (:date)', $words));
            } else {
                $this->budget->recordRates($run->project, [$item->id => $figure->rate], today()->toDateString(), Str::limit(__(':source: :item (:date)', $words), 157), $viewer);
            }

            $line->update([
                'accepted_ref' => $ref,
                'accepted_source' => $entry['source'],
                'accepted_rate' => $figure->rate,
                'accepted_mode' => $mode,
                'accepted_by' => $viewer->id,
                'accepted_at' => now(),
                'skipped_by' => null,
                'skipped_at' => null,
            ]);

            $this->closeWhenDone($run);

            return $line;
        });
    }

    /** Leaves the line without a rate from this run. It can still be accepted later. */
    public function skip(User $viewer, RateSuggestionRun $run, RateSuggestionLine $line): RateSuggestionLine
    {
        return DB::transaction(function () use ($viewer, $run, $line) {
            $line = RateSuggestionLine::query()->where('run_id', $run->id)->lockForUpdate()->findOrFail($line->id);

            if ($line->isAccepted()) {
                throw ValidationException::withMessages(['candidate' => __('A rate was already accepted for this line.')]);
            }

            if (! $line->isSkipped()) {
                $line->update(['skipped_by' => $viewer->id, 'skipped_at' => now()]);
            }

            $this->closeWhenDone($run);

            return $line;
        });
    }

    private function closeWhenDone(RateSuggestionRun $run): void
    {
        $open = RateSuggestionLine::query()->where('run_id', $run->id)->whereNull('accepted_at')->whereNull('skipped_at')->exists();
        $status = $open ? RateSuggestionRun::OPEN : RateSuggestionRun::DONE;

        if ($run->status !== $status) {
            $run->update(['status' => $status]);
        }
    }
}
