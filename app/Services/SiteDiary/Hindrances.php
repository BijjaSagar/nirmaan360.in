<?php

namespace App\Services\SiteDiary;

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\Dpr;
use App\Models\Hindrance;
use App\Models\HindranceSource;
use App\Models\Project;
use App\Models\User;
use App\Support\ClientUuidRepeat;
use App\Support\Idempotent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Writes to the hindrance register.
 *
 * An entry is made by a person from daily reports that mention the hindrance. The reports must
 * belong to the same project and say something under hindrances; what each one says is copied
 * beside the entry. Start and end are the dates the person types, or the first and last report
 * date when they leave them blank. An entry a person makes is confirmed by that person; one put
 * forward by the app or the AI waits for someone to confirm it.
 *
 * Once a finalised letter cites an entry it is locked: every change answers 409, and a correction
 * is a new entry that names the locked one.
 */
class Hindrances
{
    /**
     * @param  array<string, mixed>  $input  The request, read only to pick the rules.
     * @param  Hindrance|null  $hindrance  The entry being changed, or null when one is being made.
     */
    public function rules(Project $project, array $input, ?Hindrance $hindrance = null): array
    {
        $ongoing = filter_var($input['still_going_on'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $date = ['date_format:Y-m-d', 'before_or_equal:today'];
        $ofProject = fn (string $table) => Rule::exists($table, 'id')
            ->where('organization_id', $project->organization_id)->where('project_id', $project->id)->whereNull('deleted_at');
        $leftOut = ['exclude'];

        return [
            // Sent by the form, so pressing save twice makes one entry.
            'client_uuid' => $hindrance === null ? ['required', 'uuid'] : $leftOut,
            'supersedes_id' => $hindrance === null ? ['nullable', 'integer', $ofProject('hindrances')] : $leftOut,
            'category' => ['required', 'string', Rule::in(array_keys(config('modules.site_diary.categories')))],
            'description' => ['required', 'string', 'max:2000'],
            // Blank means the first and the last cited report date.
            'started_on' => ['nullable', ...$date],
            'ended_on' => $ongoing ? ['prohibited'] : ['nullable', ...$date, 'after_or_equal:started_on'],
            'still_going_on' => ['nullable', 'boolean'],
            'responsible' => ['nullable', 'string', 'max:160'],
            'site_id' => ['nullable', 'integer', $ofProject('sites')],
            'dpr_ids' => ['required', 'array', 'min:1', 'max:400'],
            'dpr_ids.*' => ['bail', 'integer', 'distinct', $ofProject('dprs')],
        ];
    }

    /**
     * Make an entry. A repeated client_uuid returns the original.
     *
     * @param  array<string, mixed>  $data  Validated with rules().
     *
     * @throws ValidationException
     */
    public function create(Project $project, array $data, User $by): Hindrance
    {
        $uuid = $data['client_uuid'] ?? null;
        $find = fn () => $uuid ? ClientUuidRepeat::reachable(Hindrance::withoutGlobalScopes()->where('organization_id', $project->organization_id)->where('client_uuid', $uuid)->first()) : null;

        if ($existing = $find()) {
            return $this->sameProject($existing, $project);
        }

        return $this->sameProject(Idempotent::create(fn () => DB::transaction(function () use ($project, $data, $by, $uuid) {
            $this->lockProject($project->id);

            $reports = $this->reports($project, $data['dpr_ids']);
            $corrected = $this->corrected($project, $data['supersedes_id'] ?? null);

            $hindrance = Hindrance::create([
                ...$this->columns($data, $reports),
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'status' => Hindrance::CONFIRMED,
                'proposed_by' => Hindrance::BY_PERSON,
                'supersedes_id' => $corrected?->id,
                'confirmed_by' => $by->id,
                'confirmed_at' => now(),
                'client_uuid' => $uuid,
            ]);

            $this->cite($hindrance, $reports);

            return $hindrance;
        }), $find), $project);
    }

    /**
     * Change an entry. The form sends the whole entry, and the words of every cited report are
     * read again. Whoever changes a confirmed entry becomes the person who confirmed it, so the
     * name on it is always someone who stood behind what it says now.
     *
     * @param  array<string, mixed>  $data  Validated with rules().
     *
     * @throws ValidationException
     */
    public function update(Hindrance $hindrance, array $data, User $by): Hindrance
    {
        return $this->oneAtATime($hindrance, function (Hindrance $hindrance) use ($data, $by) {
            $project = Project::withoutGlobalScopes()->where('organization_id', $hindrance->organization_id)->findOrFail($hindrance->project_id);
            $reports = $this->reports($project, $data['dpr_ids']);

            $hindrance->fill($this->columns($data, $reports));
            $changed = $hindrance->isDirty();
            $cited = $this->cite($hindrance, $reports);

            if (($changed || $cited) && $hindrance->isConfirmed()) {
                $hindrance->fill(['confirmed_by' => $by->id, 'confirmed_at' => now()]);
            }

            $hindrance->save();

            return $hindrance;
        });
    }

    /**
     * Say that an entry put forward by the app or the AI, or one dismissed earlier, stands.
     *
     * @throws ValidationException
     */
    public function confirm(Hindrance $hindrance, User $by): Hindrance
    {
        return $this->oneAtATime($hindrance, function (Hindrance $hindrance) use ($by) {
            if ($hindrance->isConfirmed()) {
                return $hindrance;
            }

            // A correction dismissed earlier cannot come back beside another correction of the same entry.
            if ($hindrance->supersedes_id !== null && $this->standingCorrections($hindrance->supersedes_id)->whereKeyNot($hindrance->id)->exists()) {
                throw ValidationException::withMessages(['status' => __('Another correction of the same entry is already in the register. Dismiss that one first.')]);
            }

            $hindrance->update(['status' => Hindrance::CONFIRMED, 'confirmed_by' => $by->id, 'confirmed_at' => now()]);

            return $hindrance;
        });
    }

    /** Take an entry out of the count. The row stays in the register, marked dismissed. */
    public function dismiss(Hindrance $hindrance): Hindrance
    {
        return $this->oneAtATime($hindrance, function (Hindrance $hindrance) {
            if ($hindrance->status !== Hindrance::DISMISSED) {
                $hindrance->update(['status' => Hindrance::DISMISSED, 'confirmed_by' => null, 'confirmed_at' => null]);
            }

            return $hindrance;
        });
    }

    /**
     * The entries of a project that count: confirmed, and not corrected by a later entry.
     * Filters by company and project itself, so it gives the same answer with nobody signed in.
     *
     * @return Builder<Hindrance>
     */
    public function standing(Project $project): Builder
    {
        return Hindrance::query()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->where('status', Hindrance::CONFIRMED)
            ->whereNotIn('id', $this->correctedIds($project))
            ->orderBy('started_on')->orderBy('id');
    }

    /** Whether the report says something under hindrances: at least one letter or digit, not only dashes or dots. */
    public static function saysSomething(?string $text): bool
    {
        return $text !== null && preg_match('/[\p{L}\p{N}]/u', $text) === 1;
    }

    /**
     * Runs one change at a time on a project, on the entry as it is saved now. A locked entry
     * answers 409 and one removed in the meantime 404.
     *
     * @param  callable(Hindrance): Hindrance  $change
     */
    private function oneAtATime(Hindrance $hindrance, callable $change): Hindrance
    {
        return DB::transaction(function () use ($hindrance, $change) {
            $this->lockProject($hindrance->project_id);
            $hindrance->refresh();

            if ($hindrance->trashed()) {
                throw (new ModelNotFoundException)->setModel(Hindrance::class, [$hindrance->id]);
            }

            if ($hindrance->isLocked()) {
                throw new ImmutableRecordException(Hindrance::lockedMessage());
            }

            return $change($hindrance);
        });
    }

    private function lockProject(int $projectId): void
    {
        Project::withoutGlobalScopes()->whereKey($projectId)->lockForUpdate()->first();
    }

    /**
     * The cited reports, oldest first. Each must still say something under hindrances.
     *
     * @param  list<int|string>  $ids
     * @return Collection<int, Dpr>
     *
     * @throws ValidationException
     */
    private function reports(Project $project, array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $reports = Dpr::query()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->whereIn('id', $ids)
            ->orderBy('report_date')->orderBy('id')
            ->get();

        if ($reports->count() !== count($ids)) {
            throw ValidationException::withMessages(['dpr_ids' => __('One of the chosen daily reports is not on this project any more. Open the register again and choose the reports once more.')]);
        }

        if ($reports->contains(fn (Dpr $report) => ! self::saysSomething($report->hindrances))) {
            throw ValidationException::withMessages(['dpr_ids' => __('One of the chosen daily reports has nothing written under hindrances any more. Leave it out, or write the hindrance in that report first.')]);
        }

        return $reports;
    }

    /**
     * The locked entry a new one corrects, or null. Only an entry a finalised letter cites is
     * corrected this way, and by one correction at a time.
     *
     * @throws ValidationException
     */
    private function corrected(Project $project, mixed $id): ?Hindrance
    {
        if ($id === null || $id === '') {
            return null;
        }

        $entry = Hindrance::query()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->find((int) $id);

        if ($entry === null || ! $entry->isLocked()) {
            throw ValidationException::withMessages(['supersedes_id' => __('Only an entry that a finalised letter cites is corrected by a new entry. Any other entry can simply be changed.')]);
        }

        if ($this->standingCorrections($entry->id)->exists()) {
            throw ValidationException::withMessages(['supersedes_id' => __('This entry already has a correction. Change that correction, or dismiss it first.')]);
        }

        return $entry;
    }

    /** @return Builder<Hindrance> */
    private function standingCorrections(int $entryId): Builder
    {
        return Hindrance::query()->where('supersedes_id', $entryId)->where('status', '!=', Hindrance::DISMISSED);
    }

    /** @return list<int> Entries of the project that a later entry corrects. */
    private function correctedIds(Project $project): array
    {
        return Hindrance::query()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->whereNotNull('supersedes_id')
            ->where('status', '!=', Hindrance::DISMISSED)
            ->pluck('supersedes_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The columns of an entry from validated data. A blank start is the first cited report date and
     * a blank end the last, unless the hindrance is still going on.
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<int, Dpr>  $reports
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function columns(array $data, Collection $reports): array
    {
        $ongoing = filter_var($data['still_going_on'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $start = filled($data['started_on'] ?? null) ? (string) $data['started_on'] : $reports->first()->report_date->toDateString();
        $end = match (true) {
            $ongoing => null,
            filled($data['ended_on'] ?? null) => (string) $data['ended_on'],
            default => $reports->last()->report_date->toDateString(),
        };

        // Two typed dates in the wrong order are refused by the rules, so here one of the two came from a report.
        if ($end !== null && $end < $start) {
            throw ValidationException::withMessages(filled($data['ended_on'] ?? null)
                ? ['started_on' => __('The end date you typed is before the first chosen report, whose date is taken as the start. Type the day the hindrance started, or choose the reports again.')]
                : ['ended_on' => __('The last chosen report is dated before the start date. Type the day the hindrance ended, or tick that it is still going on.')]);
        }

        $responsible = trim((string) ($data['responsible'] ?? ''));

        return [
            'category' => $data['category'],
            'description' => trim((string) $data['description']),
            'started_on' => $start,
            'ended_on' => $end,
            'responsible' => $responsible !== '' ? $responsible : null,
            'site_id' => $data['site_id'] ?? null,
        ];
    }

    /**
     * Makes the entry's sources match the chosen reports, copying each report's date, status and
     * words as they are now. True when anything changed; the change is written to the audit log.
     *
     * @param  Collection<int, Dpr>  $reports
     */
    private function cite(Hindrance $hindrance, Collection $reports): bool
    {
        $sources = $hindrance->exists ? HindranceSource::query()->where('hindrance_id', $hindrance->id)->get()->keyBy('dpr_id') : collect();
        $before = $this->signature($sources->values());
        $kept = [];

        foreach ($reports as $report) {
            $copy = ['report_date' => $report->report_date->toDateString(), 'excerpt' => trim((string) $report->hindrances), 'dpr_status' => (string) $report->status];
            $source = $sources->get($report->id);

            if ($source === null) {
                $source = HindranceSource::create([
                    ...$copy,
                    'organization_id' => $hindrance->organization_id,
                    'project_id' => $hindrance->project_id,
                    'hindrance_id' => $hindrance->id,
                    'dpr_id' => $report->id,
                ]);
            } else {
                $source->fill($copy)->save();
            }

            $kept[] = $source->id;
        }

        HindranceSource::query()->where('hindrance_id', $hindrance->id)->whereNotIn('id', $kept)->delete();

        $after = $this->signature(HindranceSource::query()->where('hindrance_id', $hindrance->id)->get());

        if ($before === $after) {
            return false;
        }

        // Sources are not Auditable, so a change to them is recorded against the entry.
        AuditLog::record('hindrance.reports_cited', $hindrance, $before === [] ? null : ['reports' => $before], ['reports' => $after]);
        $hindrance->unsetRelation('sources');

        return true;
    }

    /**
     * What the entry rests on, short enough for the audit log: the words of a report are kept as
     * a fingerprint, so a change of wording shows without the log holding the text twice.
     *
     * @param  Collection<int, HindranceSource>  $sources
     * @return list<array{dpr_id: int, report_date: string, dpr_status: string, words: string}>
     */
    private function signature(Collection $sources): array
    {
        return $sources
            ->sortBy(fn (HindranceSource $source) => [$source->report_date->toDateString(), $source->dpr_id])
            ->map(fn (HindranceSource $source) => [
                'dpr_id' => (int) $source->dpr_id,
                'report_date' => $source->report_date->toDateString(),
                'dpr_status' => (string) $source->dpr_status,
                'words' => substr(hash('sha256', (string) $source->excerpt), 0, 16),
            ])
            ->values()
            ->all();
    }

    private function sameProject(Hindrance $hindrance, Project $project): Hindrance
    {
        if ((int) $hindrance->project_id !== (int) $project->id) {
            throw ValidationException::withMessages(['client_uuid' => __('This request id was already used on another project.')]);
        }

        return $hindrance;
    }
}
