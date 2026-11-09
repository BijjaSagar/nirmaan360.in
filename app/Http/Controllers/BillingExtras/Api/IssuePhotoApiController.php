<?php

namespace App\Http\Controllers\BillingExtras\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\IssuePresenter;
use App\Models\Issue;
use App\Models\IssuePhoto;
use App\Services\BillingExtras\IssuePhotoService;
use App\Services\BillingExtras\PrivateFile;
use App\Support\ClientTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IssuePhotoApiController extends Controller
{
    public function __construct(private IssuePhotoService $service) {}

    public function index(Issue $issue): JsonResponse
    {
        return response()->json(['data' => $issue->photos->map(IssuePresenter::photo(...))->all()]);
    }

    public function store(Request $request, Issue $issue): JsonResponse
    {
        $data = $request->validate($this->service->rules());

        $issuePhoto = $this->service->add($issue, $request->file('photo'), [
            ...$data,
            'taken_at' => ClientTime::parse($data['taken_at'] ?? null),
        ], $request->user());

        return response()->json(['data' => IssuePresenter::photo($issuePhoto)], $issuePhoto->wasRecentlyCreated ? 201 : 200);
    }

    public function show(IssuePhoto $issuePhoto): StreamedResponse
    {
        return PrivateFile::response($issuePhoto->path);
    }

    public function destroy(IssuePhoto $issuePhoto): Response
    {
        $this->service->remove($issuePhoto);

        return response()->noContent();
    }
}
