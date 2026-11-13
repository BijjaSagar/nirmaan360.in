<?php

namespace App\Http\Controllers\BoqScan;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DocumentReading\ChecksParent;
use App\Models\BoqScanRow;
use App\Models\DocumentRead;
use App\Models\Project;
use App\Services\BoqScan\BoqReadKind;
use App\Services\BoqScan\BoqScanConfirm;
use App\Services\BoqScan\BoqScanPresenter;
use App\Services\BoqScan\BoqScanReview;
use App\Services\DocumentReading\ReadAccess;
use App\Services\DocumentReading\ReadRefused;
use App\Services\DocumentReading\ReadStatus;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The scanned BOQ reader on the web: create a read for a project, then review it one document page
 * at a time and write it to the BOQ. Every answer is JSON; the review screen calls these.
 *
 * Uploading pages, the price, starting and the status are the shared read routes. Only creating a
 * read sends anything anywhere; reviewing, correcting and confirming never depend on the provider,
 * the limits or the consent switch, so a company can always finish what was already read.
 */
class BoqScanController extends Controller
{
    use ChecksParent;

    public function __construct(
        private BoqScanReview $review,
        private BoqScanPresenter $presenter,
        private BoqScanConfirm $confirmer,
    ) {}

    public function store(Request $request, Project $project): JsonResponse
    {
        [$read, $created] = $this->review->open($project, $request->user(), $this->createRules($request), 'web');

        return response()->json(['id' => $read->id, 'status' => $read->status], $created ? 201 : 200);
    }

    public function rows(Request $request, DocumentRead $read): JsonResponse
    {
        $this->scan($request, $read);
        $data = $request->validate(['page_no' => ['nullable', 'integer', 'min:1', 'max:100000']]);

        return response()->json($this->presenter->page($read, (int) ($data['page_no'] ?? 1)));
    }

    public function update(Request $request, DocumentRead $read, BoqScanRow $row): JsonResponse
    {
        $this->assertChildOf($row, $read);
        $this->scan($request, $read);

        $data = $request->validate([
            'kind' => ['sometimes', 'nullable', Rule::in(BoqScanRow::KINDS)],
            ...$this->cellRules(),
        ]);

        return $this->answer(fn () => response()->json($this->presenter->changed($read, $this->review->update($read, $row, $data, $request->user()))));
    }

    public function add(Request $request, DocumentRead $read): JsonResponse
    {
        $this->scan($request, $read);

        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'page_no' => ['required', 'integer', 'min:1', 'max:100000'],
            'after_row_id' => ['nullable', 'integer', 'min:1'],
            'kind' => ['nullable', Rule::in(BoqScanRow::KINDS)],
            'extra' => ['nullable', 'string', 'max:20'],
            ...$this->cellRules(),
        ]);

        return $this->answer(function () use ($request, $read, $data) {
            $row = $this->review->add($read, $data, $request->user());

            return response()->json($this->presenter->changed($read, $row), $row->wasRecentlyCreated ? 201 : 200);
        });
    }

    public function join(Request $request, DocumentRead $read, BoqScanRow $row): JsonResponse
    {
        $this->assertChildOf($row, $read);
        $this->scan($request, $read);

        return $this->answer(fn () => response()->json($this->presenter->changed($read, $this->review->join($read, $row, $request->user()))));
    }

    public function exclude(Request $request, DocumentRead $read, BoqScanRow $row): JsonResponse
    {
        $this->assertChildOf($row, $read);
        $this->scan($request, $read);

        return $this->answer(fn () => response()->json($this->presenter->changed($read, $this->review->exclude($read, $row, $request->user()))));
    }

    public function confirmRow(Request $request, DocumentRead $read, BoqScanRow $row): JsonResponse
    {
        $this->assertChildOf($row, $read);
        $this->scan($request, $read);

        return $this->answer(fn () => response()->json($this->presenter->changed($read, $this->review->confirmRow($read, $row, $request->user()))));
    }

    public function overrideRow(Request $request, DocumentRead $read, BoqScanRow $row): JsonResponse
    {
        $this->assertChildOf($row, $read);
        $this->scan($request, $read);

        $data = $request->validate([
            'flag' => ['required', 'string', 'max:40'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->answer(fn () => response()->json($this->presenter->changed($read, $this->review->override($read, $row, $data['flag'], $data['reason'], $request->user()))));
    }

    public function confirmClean(Request $request, DocumentRead $read, int $page): JsonResponse
    {
        $this->scan($request, $read);
        $request->validate(['viewed' => ['required', 'boolean']]);

        return $this->answer(function () use ($request, $read, $page) {
            $confirmed = $this->review->confirmClean($read, $page, $request->boolean('viewed'), $request->user());

            return response()->json(['confirmed' => $confirmed, ...$this->presenter->page($read, $page)]);
        });
    }

    public function noLines(Request $request, DocumentRead $read, int $page): JsonResponse
    {
        $this->scan($request, $read);

        return $this->answer(function () use ($request, $read, $page) {
            $this->review->noLines($read, $page, $request->user());

            return response()->json($this->presenter->page($read, $page));
        });
    }

    public function acknowledge(Request $request, DocumentRead $read): JsonResponse
    {
        $this->scan($request, $read);

        $data = $request->validate([
            'flag' => ['required', 'string', 'max:40'],
            'row_id' => ['nullable', 'integer', 'min:1'],
            'page_no' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'extra' => ['nullable', 'string', 'max:20'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->answer(function () use ($request, $read, $data) {
            $this->review->acknowledge($read, $data, $request->user());
            $page = isset($data['page_no']) ? (int) $data['page_no'] : (int) (BoqScanRow::where('document_read_id', $read->id)->find($data['row_id'] ?? 0)?->page_no ?? 1);

            return response()->json($this->presenter->page($read, $page));
        });
    }

    public function confirm(Request $request, DocumentRead $read): JsonResponse
    {
        $this->scan($request, $read);
        $data = $request->validate(['value_reason' => ['nullable', 'string', 'max:1000']]);

        return $this->answer(function () use ($request, $read, $data) {
            $counts = $this->confirmer->confirm($read, $request->user(), $data['value_reason'] ?? null);

            return response()->json([
                'status' => DocumentRead::CONFIRMED,
                'result' => $counts,
                'boq_url' => $read->project_id === null ? null : route('boq.index', $read->project_id),
            ]);
        });
    }

    public function discard(Request $request, DocumentRead $read): JsonResponse
    {
        $this->scan($request, $read);

        return $this->answer(fn () => response()->json(app(ReadStatus::class)->body($this->review->discard($read, $request->user()))));
    }

    /** A read of this module, that this person may open. Anything else is not found. */
    private function scan(Request $request, DocumentRead $read): void
    {
        abort_unless($read->kind === BoqReadKind::KEY, 404);
        ReadAccess::assert($read, $request->user());
    }

    /** @return array{client_uuid: string, pages_total: int, language: string, source_name?: ?string} */
    private function createRules(Request $request): array
    {
        return $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'pages_total' => ['required', 'integer', 'min:1', 'max:60'],
            'language' => ['required', Rule::in(['en', 'mr', 'hi'])],
            'source_name' => ['nullable', 'string', 'max:160'],
        ]);
    }

    /** @return array<string, list<string>> */
    private function cellRules(): array
    {
        return [
            'section' => ['sometimes', 'nullable', 'string', 'max:120'],
            'code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:16'],
            'qty' => ['sometimes', 'nullable', 'string', 'max:40'],
            'rate' => ['sometimes', 'nullable', 'string', 'max:40'],
            'amount' => ['sometimes', 'nullable', 'string', 'max:40'],
            'percent' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    /** @param  Closure(): JsonResponse  $step */
    private function answer(Closure $step): JsonResponse
    {
        try {
            return $step();
        } catch (ReadRefused $refused) {
            return response()->json([...$refused->toArray(), 'message' => $refused->getMessage()], $refused->status);
        }
    }
}
