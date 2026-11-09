<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\RateLibraryItem;
use App\Services\Reports\RateLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RateLibraryController extends Controller
{
    public function __construct(private RateLibraryService $service) {}

    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'string', 'max:80']]);

        return view('modules.reports.rates', [
            'items' => $this->service->query($filters['q'] ?? null, $filters['category'] ?? null)->paginate(30)->withQueryString(),
            'categories' => $this->service->categories(),
            'filters' => $filters,
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->service->create($request->user(), $request->validate($this->service->rules()));

        return back()->with('success', __('Rate added.'));
    }

    public function update(Request $request, RateLibraryItem $rate): RedirectResponse
    {
        $data = $request->validate($this->service->rules());
        unset($data['client_uuid']);

        $this->service->update($rate, $data);

        return back()->with('success', __('Rate updated.'));
    }

    public function destroy(RateLibraryItem $rate): RedirectResponse
    {
        $rate->delete();

        return back()->with('success', __('Rate removed.'));
    }

    /** Matches for the picker on the BOQ form. */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json($this->service->query($data['q'] ?? null)->limit(15)->get()->map(fn (RateLibraryItem $item) => [
            'id' => $item->id,
            'item' => $item->item,
            'unit' => $item->unit,
            'rate' => $item->rate,
            'category' => $item->category,
        ])->values());
    }
}
