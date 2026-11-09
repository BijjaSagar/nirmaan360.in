<?php

namespace App\Http\Controllers\PriceWatch;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\MaterialPrice;
use App\Models\PriceWatchMaterial;
use App\Services\PriceWatch\PriceBoard;
use App\Services\PriceWatch\PriceRecorder;
use App\Services\PriceWatch\PriceSheet;
use App\Services\PriceWatch\PriceWatchSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** The company price board and its dated lists. Rates are money, so every route here needs money.view. */
class PriceBoardController extends Controller
{
    public function __construct(
        private PriceBoard $board,
        private PriceRecorder $recorder,
        private PriceSheet $sheet,
        private PriceWatchSettings $settings,
    ) {}

    public function index(Request $request): View
    {
        $organizationId = $request->user()->organization_id;
        $this->board->seedDefaults($organizationId);
        $rows = $this->board->rows($organizationId, today(), activeOnly: false);
        $canManage = $request->user()->can('price_watch.manage');

        return view('modules.price_watch.board', [
            'rows' => $rows->where('is_active', true)->values(),
            'inactive' => $rows->where('is_active', false)->values(),
            'staleDays' => $this->settings->staleDays(),
            'sources' => array_map(fn ($source) => ['label' => $source->label(), 'connected' => $source->isConnected(), 'updated_on' => $source->updatedOn()], $this->recorder->sources()),
            'catalogue' => $canManage ? Material::orderBy('name')->get(['id', 'code', 'name']) : collect(),
            'materialUuid' => (string) Str::uuid(),
            'priceUuid' => (string) Str::uuid(),
        ]);
    }

    public function show(Request $request, PriceWatchMaterial $watchMaterial): View
    {
        return view('modules.price_watch.material', [
            'row' => $this->board->history($watchMaterial),
            'staleDays' => $this->settings->staleDays(),
            'catalogue' => $request->user()->can('price_watch.manage') ? Material::orderBy('name')->get(['id', 'code', 'name']) : collect(),
            'priceUuid' => (string) Str::uuid(),
        ]);
    }

    public function storeMaterial(Request $request): RedirectResponse
    {
        $data = $request->validate($this->recorder->materialRules());
        $material = $this->recorder->addMaterial($request->user()->organization_id, $data, $request->user());

        return redirect()->route('price-watch.index')->with('success', __(':name is on the board. Enter its first price.', ['name' => $material->label()]));
    }

    public function updateMaterial(Request $request, PriceWatchMaterial $watchMaterial): RedirectResponse
    {
        $data = $request->validate($this->recorder->materialRules());
        $this->recorder->updateMaterial($watchMaterial, $data);

        return back()->with('success', __('Material saved.'));
    }

    public function storePrice(Request $request): RedirectResponse
    {
        $data = $request->validate($this->recorder->priceRules());
        $material = PriceWatchMaterial::findOrFail($data['price_watch_material_id']);
        $saved = $this->recorder->record($material, $data, $request->user());

        return back()->with('success', $saved === null
            ? __('That price is already the latest for :name.', ['name' => $material->label()])
            : __('Price saved for :name as on :date.', ['name' => $material->label(), 'date' => $saved->priced_on->format('d M Y')]));
    }

    public function destroyPrice(MaterialPrice $materialPrice): RedirectResponse
    {
        $materialPrice->delete();

        return back()->with('success', __('Price removed.'));
    }

    /** The board as a spreadsheet with blank price cells. */
    public function template(Request $request): Response
    {
        $bytes = $this->sheet->template($this->board->rows($request->user()->organization_id));

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="material-prices-'.today()->toDateString().'.xlsx"',
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'extensions:xlsx', 'max:5120'], 'add_missing' => ['nullable', 'boolean']]);

        $result = $this->sheet->import($request->user()->organization_id, $request->file('file')->getRealPath(), $request->boolean('add_missing'), $request->user());

        if ($result['errors'] !== []) {
            return back()->withErrors(['file' => $result['errors']])->withInput();
        }

        $message = __(':added prices added, :unchanged already on the list.', ['added' => $result['added'], 'unchanged' => $result['unchanged']]);

        if ($result['created'] > 0) {
            $message .= ' '.__(':count new materials put on the board.', ['count' => $result['created']]);
        }

        return back()->with('success', $message);
    }

    /** Take what the configured sources have that the company does not. */
    public function adopt(Request $request): RedirectResponse
    {
        $result = $this->recorder->pull($request->user()->organization_id, $request->user());

        if ($result['sources'] === []) {
            return back()->withErrors(['adopt' => __('No price source is connected. The shared list is empty.')]);
        }

        return back()->with('success', $result['added'] > 0
            ? __(':added prices taken from :sources.', ['added' => $result['added'], 'sources' => implode(', ', array_map(fn (string $label) => __($label), $result['sources']))])
            : __('Nothing new: your list already has everything the shared list knows.'));
    }
}
