<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Concerns\OrganizationScope;
use App\Models\Dpr;
use App\Models\Organization;
use App\Services\Reports\Download;
use App\Services\Reports\DprReportService;
use Illuminate\Http\Response;

/** The DPR as a PDF, for signed-in staff and, through a signed link, for whoever it is shared with. */
class DprShareController extends Controller
{
    public function __construct(private DprReportService $service) {}

    public function pdf(Dpr $dpr): Response
    {
        abort_unless(auth()->user()->organization->allowsFeature('site'), 403, __('Your plan does not include this module. Upgrade to continue.'));

        return Download::file($this->service->pdf($dpr), $this->service->filename($dpr), Download::PDF);
    }

    /**
     * Opens from a signed link with no sign-in. The signature is the only proof, so this loads the DPR
     * without the organisation filter and checks the company's plan itself.
     */
    public function shared(int $id): Response
    {
        $dpr = Dpr::withoutGlobalScope(OrganizationScope::class)->findOrFail($id);

        abort_unless(Organization::findOrFail($dpr->organization_id)->allowsFeature('reports'), 404);

        return Download::file($this->service->pdf($dpr), $this->service->filename($dpr), Download::PDF, inline: true);
    }
}
