<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetHireBill;
use App\Services\Assets\HireBillService;
use App\Support\Dec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HireBillController extends Controller
{
    public function __construct(private HireBillService $service) {}

    public function index(Request $request): View
    {
        $status = $request->query('status');

        $bills = AssetHireBill::with('asset', 'vendor')
            ->when($status, fn ($query, $value) => $query->where('status', $value))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $owed = AssetHireBill::where('status', 'FINAL')->get(['net_payable'])
            ->reduce(fn ($carry, $bill) => $carry->plus(Dec::of($bill->net_payable)), Dec::of(0));

        return view('modules.assets.hire.index', ['bills' => $bills, 'status' => $status, 'owed' => Dec::money($owed)]);
    }

    public function create(Request $request): View
    {
        return view('modules.assets.hire.create', $this->formData() + ['preview' => null, 'previewError' => null, 'selected' => $request->query('asset_id')]);
    }

    /** Work the bill out and show it, without saving. */
    public function preview(Request $request): View
    {
        $data = $request->validate($this->service->rules($request->user()));
        $asset = Asset::findOrFail($data['asset_id']);

        return view('modules.assets.hire.create', $this->formData() + [
            'preview' => $this->service->calculate($asset, $data['period_from'], $data['period_to'], $data),
            'previewAsset' => $asset,
            'selected' => $asset->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $bill = $this->service->generate($request->validate($this->service->rules($request->user())), $request->user());

        return redirect()->route('hire-bills.show', $bill)->with('success', __('Draft hire bill created.'));
    }

    public function show(AssetHireBill $bill): View
    {
        return view('modules.assets.hire.show', ['bill' => $bill->load('asset.vendor', 'vendor', 'creator', 'logs')]);
    }

    public function finalize(AssetHireBill $bill): RedirectResponse
    {
        $this->service->finalize($bill);

        return back()->with('success', __('Hire bill finalised.'));
    }

    public function paid(Request $request, AssetHireBill $bill): RedirectResponse
    {
        $this->service->markPaid($bill, $request->validate($this->service->payRules()));

        return back()->with('success', __('Hire bill marked as paid.'));
    }

    public function cancel(Request $request, AssetHireBill $bill): RedirectResponse
    {
        $this->service->cancel($bill, $request->validate($this->service->cancelRules())['reason']);

        return back()->with('success', __('Hire bill cancelled. Its machine logs can be billed again.'));
    }

    private function formData(): array
    {
        return [
            'assets' => Asset::where('ownership', 'hired')->whereNotNull('hire_basis')->where('status', '!=', 'retired')->orderBy('code')->get(['id', 'code', 'name', 'hire_basis', 'hire_rate', 'hire_type']),
        ];
    }
}
