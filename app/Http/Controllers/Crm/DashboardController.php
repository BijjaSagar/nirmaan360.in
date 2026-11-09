<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Services\Crm\OverviewService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, OverviewService $overview): View
    {
        $user = $request->user();

        return view('modules.crm.dashboard', ['summary' => $overview->summary($user->organization_id, $user)]);
    }
}
