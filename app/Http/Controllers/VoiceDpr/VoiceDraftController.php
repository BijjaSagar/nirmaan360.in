<?php

namespace App\Http\Controllers\VoiceDpr;

use App\Http\Controllers\Controller;
use App\Models\DprVoiceDraft;
use App\Models\Project;
use App\Services\DocumentReading\NothingToSend;
use App\Services\DocumentReading\ReadRefused;
use App\Services\DocumentReading\SpendRefused;
use App\Services\VoiceDpr\VoiceDraftService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The field app's voice DPR endpoints. The phone sends only the words it heard; there is no field
 * for a recording. VoiceDraftService does each step, and present() there is the shape the phone reads.
 *
 *   POST /api/v1/projects/{project}/dpr-voice-drafts   202 {id, status: "queued", ...}; 200 with the same draft on a repeat
 *   GET  /api/v1/dpr-voice-drafts/{draft}              the draft, with its proposal once "ready"
 *   POST /api/v1/dpr-voice-drafts/{draft}/link         {dpr_client_uuid, changed[]} after the person sent the DPR
 */
class VoiceDraftController extends Controller
{
    public const ON_DEVICE_REQUIRED = 'on_device_required';

    public function __construct(private VoiceDraftService $drafts) {}

    public function store(Request $request, Project $project): JsonResponse
    {
        if ($this->drafts->notOnDevice($request->input('speech'))) {
            $message = __('Voice reports are taken only from phones that turn speech into text on the phone itself.');

            return response()->json(['code' => self::ON_DEVICE_REQUIRED, 'message' => $message, 'errors' => ['speech' => [$message]]], 422);
        }

        $data = $request->validate($this->drafts->rules($project));

        return $this->answer(function () use ($project, $data, $request) {
            $draft = $this->drafts->create($project, $data, $request->user());

            return response()->json($this->drafts->present($draft), $draft->wasRecentlyCreated ? 202 : 200);
        });
    }

    public function show(Request $request, DprVoiceDraft $draft): JsonResponse
    {
        $this->drafts->assertOwn($draft, $request->user());

        return response()->json($this->drafts->present($this->drafts->watch($draft)));
    }

    public function link(Request $request, DprVoiceDraft $draft): JsonResponse
    {
        $this->drafts->assertOwn($draft, $request->user());
        $data = $request->validate($this->drafts->linkRules());

        return $this->answer(fn () => response()->json($this->drafts->present($this->drafts->link($draft, $request->user(), $data))));
    }

    /** A refusal is answered with its code, its details and a sentence in the person's language. */
    private function answer(Closure $step): JsonResponse
    {
        try {
            return $step();
        } catch (SpendRefused|ReadRefused $refused) {
            return response()->json([...$refused->toArray(), 'message' => $refused->getMessage()], $refused->status);
        } catch (NothingToSend) {
            return response()->json(['code' => 'nothing_to_send', 'message' => __('There are no words to turn into a draft.')], 422);
        }
    }
}
