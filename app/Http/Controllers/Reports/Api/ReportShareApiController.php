<?php

namespace App\Http\Controllers\Reports\Api;

use App\Http\Controllers\Controller;
use App\Models\Dpr;
use App\Models\Project;
use App\Services\Reports\Download;
use App\Services\Reports\DprReportService;
use App\Services\Reports\MeasurementSheetService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;

/**
 * The web's DPR PDF, WhatsApp share and measurement sheet, for the field app.
 * Nothing new is worked out here: the same services build the same files and the same signed link.
 */
class ReportShareApiController extends Controller
{
    public function dprPdf(Dpr $dpr, DprReportService $service): Response
    {
        return Download::file($service->pdf($dpr), $service->filename($dpr), Download::PDF);
    }

    /**
     * The link the web's "Share on WhatsApp" button carries: the web's /shared/dprs/{id}, signed for
     * DprReportService::LINK_DAYS days, with the same message around it. ?locale= (or a body field)
     * writes the message in the app's language when it differs from the account's.
     */
    public function dprShareLink(Request $request, Dpr $dpr, DprReportService $service): JsonResponse
    {
        $data = $request->validate(['locale' => ['nullable', 'string', 'in:'.implode(',', array_keys(config('buildos.locales')))]]);

        // Only for this message: the language is put back afterwards, so nothing else in the process is written in it.
        $previous = App::getLocale();

        try {
            if (! empty($data['locale'])) {
                App::setLocale($data['locale']);
            }

            $share = $service->share($dpr);
        } finally {
            App::setLocale($previous);
        }

        return response()->json(['data' => [
            'url' => $share['url'],
            'text' => $share['text'],
            'whatsapp_url' => $share['whatsapp_url'],
            'expires_at' => $share['expires_at']->toIso8601String(),
            'link_days' => DprReportService::LINK_DAYS,
            'filename' => $service->filename($dpr),
        ]]);
    }

    /** Approved measurements for a period. Rates, amounts and the total value only with money.view, as on the web. */
    public function measurementSheet(Request $request, Project $project, MeasurementSheetService $service): Response
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->startOfDay();

        return Download::file($service->pdf($project, $from, $to, Money::visible($request->user())), $service->filename($project, $from, $to), Download::PDF);
    }
}
