<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\DprPresenter;
use App\Models\Dpr;
use App\Models\DprPhoto;
use App\Models\Project;
use App\Services\DprService;
use App\Support\ClientTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DprApiController extends Controller
{
    public function __construct(private DprService $service) {}

    public function index(Project $project): JsonResponse
    {
        return response()->json(
            $project->dprs()->with(DprPresenter::RELATIONS)->latest('report_date')->latest('id')->paginate(25)
                ->through(DprPresenter::present(...))
        );
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $dpr = $this->service->create($project, $request->validate($this->service->rules($project)), $request->user());

        return response()->json(['data' => DprPresenter::present($dpr)], $dpr->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Dpr $dpr): JsonResponse
    {
        return response()->json(['data' => DprPresenter::detail($dpr->load(DprPresenter::RELATIONS))]);
    }

    public function update(Request $request, Dpr $dpr): JsonResponse
    {
        $data = $request->validate($this->service->rules($dpr->project));
        unset($data['client_uuid']);

        return response()->json(['data' => DprPresenter::present($this->service->update($dpr, $data))]);
    }

    public function submit(Request $request, Dpr $dpr): JsonResponse
    {
        $this->service->submit($dpr, $request->user());

        return response()->json(['data' => DprPresenter::present($dpr->refresh())]);
    }

    public function storePhoto(Request $request, Dpr $dpr): JsonResponse
    {
        $data = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'client_uuid' => ['nullable', 'uuid'],
            'caption' => ['nullable', 'string', 'max:255'],
            'taken_at' => ['nullable', 'date'],
        ]);

        $photo = $this->service->addPhoto($dpr, $request->file('photo'), [
            'client_uuid' => $data['client_uuid'] ?? null,
            'caption' => $data['caption'] ?? null,
            'taken_at' => ClientTime::parse($data['taken_at'] ?? null),
        ]);

        return response()->json(['data' => ['id' => $photo->id, 'caption' => $photo->caption]], $photo->wasRecentlyCreated ? 201 : 200);
    }

    public function photo(DprPhoto $photo): StreamedResponse
    {
        abort_unless(Storage::exists($photo->path), 404);

        return Storage::response($photo->path);
    }
}
