<?php

namespace App\Http\Controllers;

use App\Http\Presenters\ContractorControlPresenter;
use App\Models\BoqItem;
use App\Models\Project;
use App\Services\BoqService;
use App\Services\ContractorControl\BoqLineTenders;
use App\Services\ProjectSetup\BoqCategories;
use App\Services\ProjectSetup\BoqRevisions;
use App\Support\Dec;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BoqController extends Controller
{
    private const COLUMNS = ['section', 'code', 'description', 'unit', 'qty', 'rate'];

    /** Read when the file has them. A file without them still imports, and leaves them as they are. */
    private const OPTIONAL_COLUMNS = ['category', 'details'];

    public function __construct(private BoqService $service, private BoqLineTenders $lineTenders) {}

    public function index(Request $request, Project $project): View
    {
        $items = BoqItem::withLedger()->with('section')->where('project_id', $project->id)->orderBy('sort')->orderBy('id')->get();

        // Values are money, so they are only worked out for someone who may see them.
        $values = Money::visible() ? $this->values($items) : null;

        return view('boq.index', [
            'project' => $project,
            'items' => $items,
            'total' => $values['grand']['amount'] ?? null,
            'values' => $values,
            'tenders' => $this->tenderMarkers($request, $project),
        ]);
    }

    /**
     * Beside each line, for people who may see tenders: how many it is in and how many quotes came in.
     * The lowest quoted rate is money, so the presenter drops it for everyone else.
     *
     * @return array<int, array<string, mixed>>|null BOQ item id => marker. Null when tenders are not this person's to see.
     */
    private function tenderMarkers(Request $request, Project $project): ?array
    {
        $user = $request->user();

        return ContractorControlPresenter::tenderMarkers($this->lineTenders->markersFor($user, $project), Money::visible($user));
    }

    /**
     * Each line's value (BOQ quantity × rate) and the value of what is still to be measured
     * (balance quantity × rate), with totals for each section and for the whole BOQ.
     *
     * @return array{lines: array<int, array{amount:string, balance:string}>, sections: array<string, array{amount:string, balance:string}>, grand: array{amount:string, balance:string}}
     */
    private function values($items): array
    {
        $lines = [];
        $sections = [];
        $grand = ['amount' => Dec::of(0), 'balance' => Dec::of(0)];

        foreach ($items as $item) {
            $amount = Dec::of($item->amount());
            $balance = Dec::of($item->balanceQty())->multipliedBy(Dec::of($item->rate));
            $section = (string) $item->section?->name;

            $lines[$item->id] = ['amount' => Dec::money($amount), 'balance' => Dec::money($balance)];
            $sections[$section]['amount'] = ($sections[$section]['amount'] ?? Dec::of(0))->plus($amount);
            $sections[$section]['balance'] = ($sections[$section]['balance'] ?? Dec::of(0))->plus($balance);
            $grand['amount'] = $grand['amount']->plus($amount);
            $grand['balance'] = $grand['balance']->plus($balance);
        }

        $money = fn (array $pair) => ['amount' => Dec::money($pair['amount']), 'balance' => Dec::money($pair['balance'])];

        return ['lines' => $lines, 'sections' => array_map($money, $sections), 'grand' => $money($grand)];
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->service->create($project, $this->validated($request));

        return back()->with('success', __('BOQ item added.'));
    }

    public function update(Request $request, BoqItem $item): RedirectResponse
    {
        $data = $request->validate([...$this->service->rules(), ...$this->service->reasonRules()]);

        $this->service->update($item, $data, $data['reason']);

        return back()->with('success', __('BOQ item updated.'));
    }

    public function destroy(Request $request, BoqItem $item): RedirectResponse
    {
        $data = $request->validate($this->service->reasonRules());

        $this->service->delete($item, $data['reason']);

        return back()->with('success', __('BOQ item removed.'));
    }

    public function import(Request $request, Project $project): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $columns = $this->columnsFor($request);
        $rows = $this->readCsv($request->file('file')->getRealPath(), $columns);

        DB::transaction(function () use ($rows, $project) {
            BoqRevisions::with(__('Imported from a CSV file'), 'csv_import', function () use ($rows, $project) {
                $sort = (int) $project->boqItems()->max('sort');

                foreach ($rows as $row) {
                    $values = [
                        'boq_section_id' => $this->service->sectionId($project, $row['section']),
                        'description' => $row['description'],
                        'unit' => $row['unit'],
                        'qty' => Dec::qty($row['qty']),
                        ...Arr::only($row, self::OPTIONAL_COLUMNS),
                    ];

                    // A file from someone who may not see money has no rate column; existing rates are kept.
                    if (array_key_exists('rate', $row)) {
                        $values['rate'] = Dec::money($row['rate']);
                    }

                    $existing = $project->boqItems()->where('code', $row['code'])->first();

                    if ($existing) {
                        $existing->update($values);
                    } else {
                        BoqItem::create([
                            'rate' => 0,
                            ...$values,
                            'organization_id' => $project->organization_id,
                            'project_id' => $project->id,
                            'code' => $row['code'],
                            'sort' => ++$sort,
                        ]);
                    }
                }
            }, batch: true);
        });

        return back()->with('success', __(':count BOQ rows imported.', ['count' => count($rows)]));
    }

    public function export(Request $request, Project $project): StreamedResponse
    {
        $items = $project->boqItems()->with('section')->orderBy('sort')->orderBy('id')->get();
        $columns = $this->columnsFor($request);

        return response()->streamDownload(function () use ($items, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [...$columns, ...self::OPTIONAL_COLUMNS], ',', '"', '');

            foreach ($items as $item) {
                $cells = [
                    'section' => $this->safeCell($item->section?->name),
                    'code' => $this->safeCell($item->code),
                    'description' => $this->safeCell($item->description),
                    'unit' => $this->safeCell($item->unit),
                    'qty' => $item->qty,
                    'rate' => $item->rate,
                ];

                fputcsv($out, [
                    ...array_values(array_intersect_key($cells, array_flip($columns))),
                    $this->safeCell($item->category),
                    $this->safeCell($item->details),
                ], ',', '"', '');
            }

            fclose($out);
        }, 'boq-'.$project->code.'.csv', ['Content-Type' => 'text/csv']);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, [...self::COLUMNS, ...self::OPTIONAL_COLUMNS], ',', '"', '');
            fputcsv($out, ['Substructure', '1.1', 'Earthwork in excavation in all soils', 'cum', '250.000', '185.00', 'excavation', ''], ',', '"', '');
            fputcsv($out, ['Superstructure', '2.1', 'RCC M25 in slab including shuttering', 'cum', '120.000', '7450.00', 'rcc', 'M25 grade, 150 mm thick, with centering and shuttering.'], ',', '"', '');
            fclose($out);
        }, 'boq-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Site staff work with quantities only: the rate column is left out of their files, both ways.
     *
     * @return list<string>
     */
    private function columnsFor(Request $request): array
    {
        return Money::visible($request->user()) ? self::COLUMNS : array_values(array_diff(self::COLUMNS, ['rate']));
    }

    /**
     * @param  list<string>  $columns
     * @return list<array<string,string>>
     */
    private function readCsv(string $path, array $columns): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ',', '"', '');
        $header = array_map(fn ($cell) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $cell))), $header ?: []);

        if (array_diff($columns, $header) !== []) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => __('The file must have these columns: :columns', ['columns' => implode(', ', $columns)])]);
        }

        // Category and details are read when the file has them. They are not money, so every role may keep them.
        $wanted = [...$columns, ...array_values(array_intersect(self::OPTIONAL_COLUMNS, $header))];

        $rows = [];
        $errors = [];
        $line = 1;

        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;

            if ($cells === [null] || count($cells) !== count($header)) {
                if ($cells !== [null]) {
                    $errors[] = __('Line :line has the wrong number of columns.', ['line' => $line]);
                }

                continue;
            }

            $row = array_intersect_key(array_map('trim', array_combine($header, array_map('strval', $cells))), array_flip($wanted));

            if (isset($row['category']) && $row['category'] !== '') {
                $category = BoqCategories::fromText($row['category']);

                if ($category === null) {
                    $errors[] = __('Line :line has an unknown category. Use one of: :categories', ['line' => $line, 'categories' => implode(', ', BoqCategories::keys())]);

                    continue;
                }

                $row['category'] = $category;
            }

            if (isset($row['category']) && $row['category'] === '') {
                $row['category'] = null;
            }

            if (isset($row['details']) && $row['details'] === '') {
                $row['details'] = null;
            }

            if (isset($row['details']) && mb_strlen($row['details']) > 10000) {
                $errors[] = __('Line :line has details longer than 10000 characters.', ['line' => $line]);

                continue;
            }

            $badRate = array_key_exists('rate', $row) && (! is_numeric($row['rate']) || $row['rate'] < 0);

            if ($row['code'] === '' || $row['description'] === '' || $row['unit'] === '' || ! is_numeric($row['qty']) || $row['qty'] < 0 || $badRate) {
                $errors[] = __('Line :line is incomplete or has an invalid number.', ['line' => $line]);

                continue;
            }

            $rows[] = $row;
        }

        fclose($handle);

        if ($errors !== [] || $rows === []) {
            throw ValidationException::withMessages(['file' => $errors ?: [__('The file has no rows.')]]);
        }

        return $rows;
    }

    /** Stops spreadsheet apps running a text cell as a formula. */
    private function safeCell(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private function validated(Request $request): array
    {
        $rules = $this->service->rules();

        // Rates are money: someone who may not see them cannot set them either.
        // A new line waits for an estimator to price it, and an edited line keeps its rate.
        if (! Money::visible($request->user())) {
            unset($rules['rate']);
        }

        return $request->validate($rules);
    }
}
