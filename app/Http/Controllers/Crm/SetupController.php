<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmCustomField;
use App\Models\CrmOutcomeReason;
use App\Services\Crm\MessageService;
use App\Services\Crm\ReasonService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Templates, win/loss reasons and custom fields, on one page with three tabs. */
class SetupController extends Controller
{
    public function __invoke(Request $request, MessageService $messages, ReasonService $reasons): View
    {
        $organizationId = $request->user()->organization_id;
        $reasons->ensureDefaults($organizationId);

        return view('modules.crm.setup', [
            'tab' => in_array($request->query('tab'), ['templates', 'reasons', 'fields'], true) ? $request->query('tab') : 'templates',
            'templates' => $messages->templates($organizationId, onlyActive: false),
            'reasons' => CrmOutcomeReason::query()->where('organization_id', $organizationId)->orderBy('sort')->orderBy('id')->get()->groupBy('kind'),
            'fields' => CrmCustomField::query()->where('organization_id', $organizationId)->orderBy('sort')->orderBy('id')->get()->groupBy('entity'),
            'placeholders' => MessageService::PLACEHOLDERS,
        ]);
    }
}
