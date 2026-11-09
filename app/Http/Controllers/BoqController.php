<?php

namespace App\Http\Controllers;

use App\Models\BoqItem;
use App\Models\Project;
use App\Services\BoqService;
use App\Services\ProjectSetup\BoqCategories;
use App\Services\ProjectSetup\BoqRevisions;
use App\Support\Dec;
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

    public function __construct(private BoqService $service) {}

    public function index(Project $project): View
    {
        $items = BoqItem::withLedger()->with('section')->where('project_id', $project->id)->orderBy('sort')->orderBy('id')->get();

        $total = $items->reduce(fn ($sum, $item) => $sum->plus(Dec::of($item->amount())), Dec::of(0));

        return view('boq.index', ['project' => $project, 'items' => $items, 'total' => Dec::money($total)]);
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

        $rows = $this->readCsv($request->file('file')->getRealPath());

        DB::transaction(function () use ($rows, $project) {
            BoqRevisions::with(__('Imported from a CSV file'), 'csv_import', function () use ($rows, $project) {
                $sort = (int) $project->boqItems()->max('sort');

                foreach ($rows as $row) {
                    $values = [
                        'boq_section_id' => $this->service->sectionId($project, $row['section']),
                        'description' => $row['description'],
                        'unit' => $row['unit'],
                        'qty' => Dec::qty($row['qty']),
                        'rate' => Dec::money($row['rate']),
                        ...Arr::only($row, self::OPTIONAL_COLUMNS),
                    ];

                    $existing = $project->boqItems()->where('code', $row['code'])->first();

                    if ($existing) {
                        $existing->update($values);
                    } else {
                        BoqItem::create([
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

    public function export(Project $project): StreamedResponse
    {
        $items = $project->boqItems()->with('section')->orderBy('sort')->orderBy('id')->get();

        return response()->streamDownload(function () use ($items) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [...self::COLUMNS, ...self::OPTIONAL_COLUMNS], ',', '"', '');

            foreach ($items as $item) {
                fputcsv($out, [
                    $this->safeCell($item->section?->name),
                    $this->safeCell($item->code),
                    $this->safeCell($item->description),
                    $this->safeCell($item->unit),
                    $item->qty,
                    $item->rate,
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

    /** @return list<array<string,string>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ',', '"', '');
        $header = array_map(fn ($cell) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $cell))), $header ?: []);

        if (array_diff(self::COLUMNS, $header) !== []) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => __('The file must have these columns: :columns', ['columns' => implode(', ', self::COLUMNS)])]);
        }

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

            $row = array_map('trim', array_combine($header, array_map('strval', $cells)));

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

            if ($row['code'] === '' || $row['description'] === '' || $row['unit'] === '' || ! is_numeric($row['qty']) || ! is_numeric($row['rate']) || $row['qty'] < 0 || $row['rate'] < 0) {
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
        return $request->validate($this->service->rules());
    }
}
