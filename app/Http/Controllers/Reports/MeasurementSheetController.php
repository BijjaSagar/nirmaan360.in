<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Reports\Download;
use App\Services\Reports\MeasurementSheetService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MeasurementSheetController extends Controller
{
    public function __invoke(Request $request, Project $project, MeasurementSheetService $service): Response
    {
        abort_unless($request->user()->organization->allowsFeature('measurement'), 403, __('Your plan does not include this module. Upgrade to continue.'));

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->startOfDay();

        // Site staff get the quantities; the rates, amounts and total value need money.view.
        return Download::file($service->pdf($project, $from, $to, Money::visible($request->user())), $service->filename($project, $from, $to), Download::PDF);
    }
}
