<?php

namespace App\Services\Planning;

use App\Models\AuditLog;
use App\Models\PlanActivity;
use App\Models\PlanDependency;
use App\Models\PlanWbsNode;
use App\Models\Project;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reads the XML format Microsoft Project saves (File > Save As > XML). Summary tasks become
 * WBS nodes and the rest become activities. Tasks already imported are matched by their UID
 * and updated, leaving their BOQ links and baseline alone. One bad task stops the whole file.
 */
class MsProjectImporter
{
    private const MAX_TASKS = 5000;

    private const DEFAULT_MINUTES_PER_DAY = 480;

    private const MINUTES_IN_A_DAY = 1440;

    /** MS Project's numeric link types. A missing type means finish-to-start. */
    private const LINK_TYPES = ['0' => 'FF', '1' => 'FS', '2' => 'SF', '3' => 'SS'];

    /** Lag formats counted in elapsed time rather than working time. */
    private const ELAPSED_FORMATS = ['4', '6', '8', '10', '12'];

    /** Lag formats that are a percentage of the predecessor's length. */
    private const PERCENT_FORMATS = ['19', '20'];

    public function __construct(private ScheduleService $schedule) {}

    /**
     * Read the tasks out of the file without touching the database.
     *
     * @return array{tasks:list<array<string, mixed>>, percent_lags:int}
     */
    public function parse(string $xml): array
    {
        $root = $this->rootElement($xml);
        $minutesPerDay = max(1, (int) ($this->text($root, 'MinutesPerDay') ?: self::DEFAULT_MINUTES_PER_DAY));
        $tasks = [];
        $parents = [];
        $seen = [];
        $percentLags = 0;

        foreach ($this->children($this->children($root, 'Tasks')[0] ?? null, 'Task') as $element) {
            if ($this->isIgnored($element)) {
                continue;
            }

            $task = $this->readTask($element, $minutesPerDay, $percentLags);
            $task['parent'] = $parents[$task['level'] - 1] ?? null;

            if (isset($seen[$task['uid']])) {
                $this->fail(__('Task UID :uid appears more than once in this file.', ['uid' => $task['uid']]));
            }

            $seen[$task['uid']] = true;
            $tasks[] = $task;

            if ($task['summary']) {
                $parents[$task['level']] = $task['uid'];
                $parents = array_filter($parents, fn (int $level) => $level <= $task['level'], ARRAY_FILTER_USE_KEY);
            }

            if (count($tasks) > self::MAX_TASKS) {
                $this->fail(__('This file has more than :count tasks.', ['count' => self::MAX_TASKS]));
            }
        }

        if ($tasks === []) {
            $this->fail(__('No tasks were found in this file.'));
        }

        return ['tasks' => $tasks, 'percent_lags' => $percentLags];
    }

    /**
     * Save a file's tasks and links in one transaction.
     *
     * @return array{activities:int, wbs:int, links:int, skipped_links:int, percent_lags:int}
     */
    public function import(Project $project, string $xml): array
    {
        $parsed = $this->parse($xml);

        return DB::transaction(function () use ($project, $parsed) {
            $project = Project::lockForUpdate()->findOrFail($project->id);

            $nodeIds = $this->saveNodes($project, $parsed['tasks']);
            $activityIds = $this->saveActivities($project, $parsed['tasks'], $nodeIds);
            [$links, $skipped] = $this->saveLinks($project, $parsed['tasks'], $activityIds);

            $order = $this->schedule->dependencyOrder(
                PlanActivity::where('project_id', $project->id)->pluck('id')->all(),
                PlanDependency::where('project_id', $project->id)->get(),
            );

            if ($order === null) {
                $this->fail(__('The links in this file form a loop, so nothing was imported.'));
            }

            $result = [
                'activities' => count($activityIds),
                'wbs' => count($nodeIds),
                'links' => $links,
                'skipped_links' => $skipped,
                'percent_lags' => $parsed['percent_lags'],
            ];

            AuditLog::record('planning.import', $project, null, $result);

            return $result;
        });
    }

    private function rootElement(string $xml): DOMElement
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        // No entity substitution and no network: a file is only ever data.
        $loaded = $xml !== '' && $document->loadXML($xml, LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            $this->fail(__('This file is not valid XML.'));
        }

        if ($document->doctype !== null) {
            $this->fail(__('This file declares a document type, which is not allowed.'));
        }

        if ($document->documentElement?->localName !== 'Project') {
            $this->fail(__('This is not a Microsoft Project XML file.'));
        }

        return $document->documentElement;
    }

    /** Blank rows, the project's own summary line, inactive tasks and tasks owned by another project. */
    private function isIgnored(DOMElement $element): bool
    {
        return $this->text($element, 'IsNull') === '1'
            || $this->text($element, 'UID') === '0'
            || $this->text($element, 'Active') === '0'
            || $this->text($element, 'ExternalTask') === '1';
    }

    /** @return array<string, mixed> */
    private function readTask(DOMElement $element, int $minutesPerDay, int &$percentLags): array
    {
        $uid = (string) $this->text($element, 'UID');

        if ($uid === '') {
            $this->fail(__('A task in this file has no UID.'));
        }

        $name = trim((string) $this->text($element, 'Name'));
        $label = $name !== '' ? $name : 'UID '.$uid;
        $summary = $this->text($element, 'Summary') === '1';
        $milestone = $this->text($element, 'Milestone') === '1';

        [$start, $end] = $summary ? [null, null] : $this->taskDates($element, $label);

        if ($milestone && ! $summary) {
            $end = $start;
        }

        return [
            'uid' => mb_substr($uid, 0, 40),
            'name' => mb_substr($label, 0, 255),
            'summary' => $summary,
            'milestone' => $milestone,
            'level' => max(1, (int) $this->text($element, 'OutlineLevel')),
            'code' => $this->text($element, 'WBS') ?: ($this->text($element, 'OutlineNumber') ?: 'UID-'.$uid),
            'start' => $start,
            'end' => $end,
            'links' => $this->readLinks($element, $minutesPerDay, $percentLags),
        ];
    }

    /** @return array{0:string, 1:string} */
    private function taskDates(DOMElement $element, string $label): array
    {
        $start = $this->parseDate($this->text($element, 'Start'));
        $finish = $this->parseDate($this->text($element, 'Finish'));

        if ($start === null || $finish === null) {
            $this->fail(__('Task ":task" has no valid start or finish date.', ['task' => $label]));
        }

        [$startDate] = $start;
        [$finishDate, $finishTime] = $finish;

        // A finish at midnight belongs to the day before it.
        if ($finishTime === '00:00' && Days::of($finishDate) > Days::of($startDate)) {
            $finishDate = Days::toDate(Days::of($finishDate) - 1);
        }

        if (Days::of($finishDate) < Days::of($startDate)) {
            $this->fail(__('Task ":task" finishes before it starts.', ['task' => $label]));
        }

        return [$startDate, $finishDate];
    }

    /** @return array{0:string, 1:string}|null The date and the HH:MM time. */
    private function parseDate(?string $value): ?array
    {
        if ($value === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}:\d{2})/', $value, $match) !== 1) {
            return null;
        }

        if (! checkdate((int) $match[2], (int) $match[3], (int) $match[1]) || (int) $match[1] < 2000 || (int) $match[1] >= 2100) {
            return null;
        }

        return ["{$match[1]}-{$match[2]}-{$match[3]}", $match[4]];
    }

    /** @return list<array{uid:string, type:string, lag:int, cross:bool}> */
    private function readLinks(DOMElement $element, int $minutesPerDay, int &$percentLags): array
    {
        $links = [];

        foreach ($this->children($element, 'PredecessorLink') as $link) {
            $format = (string) $this->text($link, 'LagFormat');
            $lagTenths = (int) $this->text($link, 'LinkLag');
            $lag = 0;

            if (in_array($format, self::PERCENT_FORMATS, true)) {
                $percentLags += $lagTenths !== 0 ? 1 : 0;
            } else {
                $minutes = in_array($format, self::ELAPSED_FORMATS, true) ? self::MINUTES_IN_A_DAY : $minutesPerDay;
                $lag = (int) round($lagTenths / 10 / $minutes);
            }

            $links[] = [
                'uid' => mb_substr((string) $this->text($link, 'PredecessorUID'), 0, 40),
                'type' => self::LINK_TYPES[(string) $this->text($link, 'Type')] ?? 'FS',
                'lag' => max(-365, min(365, $lag)),
                'cross' => $this->text($link, 'CrossProject') === '1',
            ];
        }

        return $links;
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return array<string, int> Node id by task UID.
     */
    private function saveNodes(Project $project, array $tasks): array
    {
        $existing = PlanWbsNode::where('project_id', $project->id)->get();
        $byUid = $existing->whereNotNull('external_uid')->keyBy('external_uid');
        $taken = $existing->pluck('code')->flip()->all();

        $ids = [];

        foreach ($tasks as $position => $task) {
            if (! $task['summary']) {
                continue;
            }

            $node = $byUid->get($task['uid']);
            $values = [
                'parent_id' => $task['parent'] === null ? null : ($ids[$task['parent']] ?? null),
                'code' => $this->uniqueCode($task['code'], $taken, $node?->code),
                'name' => $task['name'],
                'sort' => $position,
            ];

            if ($node) {
                $node->update($values);
            } else {
                $node = PlanWbsNode::create([
                    ...$values,
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'external_uid' => $task['uid'],
                ]);
            }

            $ids[$task['uid']] = $node->id;
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @param  array<string, int>  $nodeIds
     * @return array<string, int> Activity id by task UID.
     */
    private function saveActivities(Project $project, array $tasks, array $nodeIds): array
    {
        $existing = PlanActivity::where('project_id', $project->id)->get();
        $byUid = $existing->whereNotNull('external_uid')->keyBy('external_uid');
        $taken = $existing->pluck('code')->flip()->all();
        $ids = [];

        foreach ($tasks as $position => $task) {
            if ($task['summary']) {
                continue;
            }

            $activity = $byUid->get($task['uid']);
            $values = [
                'wbs_node_id' => $task['parent'] === null ? null : ($nodeIds[$task['parent']] ?? null),
                'code' => $this->uniqueCode($task['code'], $taken, $activity?->code),
                'name' => $task['name'],
                'planned_start' => $task['start'],
                'planned_end' => $task['end'],
                'is_milestone' => $task['milestone'],
                'sort' => $position,
            ];

            if ($activity) {
                $activity->update($values);
            } else {
                $activity = PlanActivity::create([
                    ...$values,
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'external_uid' => $task['uid'],
                ]);
            }

            $ids[$task['uid']] = $activity->id;
        }

        return $ids;
    }

    /**
     * The file decides the links between its own tasks. Links a user added to activities
     * outside this file are left alone.
     *
     * @param  list<array<string, mixed>>  $tasks
     * @param  array<string, int>  $activityIds
     * @return array{0:int, 1:int} Links saved and links skipped.
     */
    private function saveLinks(Project $project, array $tasks, array $activityIds): array
    {
        PlanDependency::where('project_id', $project->id)
            ->whereIn('predecessor_id', $activityIds)
            ->whereIn('successor_id', $activityIds)
            ->get()
            ->each->delete();

        $saved = 0;
        $skipped = 0;
        $seen = [];

        foreach ($tasks as $task) {
            foreach ($task['links'] as $link) {
                $from = $activityIds[$link['uid']] ?? null;
                $to = $activityIds[$task['uid']] ?? null;

                // Links to summary tasks or other projects have no activity to attach to.
                if ($from === null || $to === null || $link['cross']) {
                    $skipped++;

                    continue;
                }

                if (isset($seen[$from.':'.$to])) {
                    continue;
                }

                $seen[$from.':'.$to] = true;

                PlanDependency::create([
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'predecessor_id' => $from,
                    'successor_id' => $to,
                    'type' => $link['type'],
                    'lag_days' => $link['lag'],
                ]);

                $saved++;
            }
        }

        return [$saved, $skipped];
    }

    /** @param array<string, mixed> $taken */
    private function uniqueCode(string $wanted, array &$taken, ?string $current): string
    {
        $wanted = mb_substr($wanted, 0, 40);

        if ($wanted === $current) {
            return $wanted;
        }

        $code = $wanted;

        for ($suffix = 2; isset($taken[$code]); $suffix++) {
            $code = mb_substr($wanted, 0, 34).'-'.$suffix;
        }

        $taken[$code] = true;

        return $code;
    }

    /** @return list<DOMElement> Direct child elements with this name. */
    private function children(?DOMElement $parent, string $name): array
    {
        $found = [];

        foreach ($parent?->childNodes ?? [] as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                $found[] = $child;
            }
        }

        return $found;
    }

    private function text(DOMElement $parent, string $name): ?string
    {
        $child = $this->children($parent, $name)[0] ?? null;

        return $child === null ? null : trim($child->textContent);
    }

    /** @throws ValidationException */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
