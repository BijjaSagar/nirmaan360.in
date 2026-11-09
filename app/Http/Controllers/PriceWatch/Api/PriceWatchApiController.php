<?php

namespace App\Http\Controllers\PriceWatch\Api;

use App\Http\Controllers\Controller;
use App\Models\PriceWatchMaterial;
use App\Models\Project;
use App\Services\PriceWatch\PriceBoard;
use App\Services\PriceWatch\PriceWatchPresenter;
use App\Services\PriceWatch\PriceWatchSettings;
use App\Services\PriceWatch\ProjectPriceImpact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only mobile endpoints for owners and project managers. The board needs money.view and
 * the project impact money.overview; anyone else is refused at the route and gets no figures.
 */
class PriceWatchApiController extends Controller
{
    public function __construct(private PriceBoard $board, private ProjectPriceImpact $impact, private PriceWatchSettings $settings) {}

    public function board(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organization_id;
        $this->board->seedDefaults($organizationId);

        return response()->json([
            'data' => $this->board->rows($organizationId)->map(PriceWatchPresenter::board(...))->all(),
            'meta' => $this->meta(),
        ]);
    }

    public function material(PriceWatchMaterial $watchMaterial): JsonResponse
    {
        return response()->json(['data' => PriceWatchPresenter::history($this->board->history($watchMaterial)), 'meta' => $this->meta()]);
    }

    public function project(Project $project): JsonResponse
    {
        $rows = $this->impact->rows($project);

        return response()->json([
            'data' => $rows->map(PriceWatchPresenter::project(...))->all(),
            'summary' => $this->impact->summary($project, $rows),
            'meta' => $this->meta(),
        ]);
    }

    /** @return array<string, mixed> */
    private function meta(): array
    {
        return ['as_of' => today()->toDateString(), 'stale_after_days' => $this->settings->staleDays(), 'live_feed' => false];
    }
}
