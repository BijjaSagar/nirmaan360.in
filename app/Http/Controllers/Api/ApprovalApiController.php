<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ApprovalPresenter;
use App\Models\Approval;
use App\Services\ApprovalService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApprovalApiController extends Controller
{
    public function __construct(private ApprovalService $service) {}

    public function index(Request $request): JsonResponse
    {
        [$mine, $waiting] = $this->service->inbox($request->user());

        return response()->json([
            'data' => ApprovalPresenter::collection($mine, $request->user()),
            'waiting_count' => $waiting->count(),
        ]);
    }

    public function act(Request $request, Approval $approval): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'return', 'reject'])],
            'remark' => ['nullable', 'string', 'max:1000'],
            'client_uuid' => ['nullable', 'uuid'],
        ]);

        $approval = $this->service->act($approval, $request->user(), $data['action'], $data['remark'] ?? null, $data['client_uuid'] ?? null);

        return response()->json(['data' => ApprovalPresenter::collection(new Collection([$approval]), $request->user())[0]]);
    }
}
