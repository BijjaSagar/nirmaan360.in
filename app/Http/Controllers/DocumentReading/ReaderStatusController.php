<?php

namespace App\Http\Controllers\DocumentReading;

use App\Http\Controllers\Controller;
use App\Services\DocumentReading\ReaderAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Tells the field app, for the signed-in person, whether the AI readers can be offered and which of them. */
class ReaderStatusController extends Controller
{
    public function __invoke(Request $request, ReaderAvailability $availability): JsonResponse
    {
        return response()->json($availability->for($request->user()));
    }
}
