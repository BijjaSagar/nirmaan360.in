<?php

namespace App\Http\Controllers\Drawings;

use App\Http\Controllers\Controller;
use App\Models\DrawingIssuePin;
use App\Models\DrawingSheet;
use App\Services\Drawings\IssuePinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PinController extends Controller
{
    public function __construct(private IssuePinService $service) {}

    public function store(Request $request, DrawingSheet $sheet): JsonResponse
    {
        $data = $request->validate($this->service->rules($sheet, $request->user()));
        $pin = $this->service->pin($sheet, $data, $request->user());

        return response()->json(['pin' => $this->service->present($pin->load('issue.assignee'))], $pin->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(DrawingIssuePin $pin): JsonResponse
    {
        $this->service->remove($pin);

        return response()->json(['deleted' => true]);
    }
}
