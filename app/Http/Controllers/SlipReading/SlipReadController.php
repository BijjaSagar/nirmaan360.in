<?php

namespace App\Http\Controllers\SlipReading;

use App\Http\Controllers\Controller;
use App\Models\DocumentRead;
use App\Models\Project;
use App\Models\SlipRead;
use App\Services\DocumentReading\ImageRefused;
use App\Services\DocumentReading\ImageTokens;
use App\Services\DocumentReading\ReaderUnavailable;
use App\Services\DocumentReading\ReadRefused;
use App\Services\DocumentReading\SpendRefused;
use App\Services\SlipReading\SlipReadService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reading a photo to fill a form, for the browser and the field app. Every answer is JSON: the
 * form's own script calls these and fills the form from the answer. Nothing here saves a challan or
 * a bill; the form does that through its own route.
 *
 * A reading is one person's own. Someone else of the company, other than an owner or an admin,
 * gets a 403; another company's reading is not found.
 */
class SlipReadController extends Controller
{
    public function __construct(private SlipReadService $slips) {}

    /** A supplier bill is read on the web only, by someone who sees money. */
    public function store(Request $request, Project $project): JsonResponse
    {
        $api = $request->is('api/*');

        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'kind' => ['required', Rule::in($api ? [SlipRead::CHALLAN] : [SlipRead::CHALLAN, SlipRead::BILL])],
            'image' => ['required', 'file', 'max:'.(int) config('modules.document_reading.upload.page_kb')],
        ]);

        abort_unless($this->slips->mayUseKind($request->user(), $data['kind']), 403);

        return $this->answer(function () use ($request, $project, $data, $api) {
            $slip = $this->slips->create($project, $request->user(), $data['kind'], (string) file_get_contents($request->file('image')->getRealPath()), $data['client_uuid'], $api ? 'mobile' : 'web');
            $read = DocumentRead::withoutGlobalScopes()->where('organization_id', $slip->organization_id)->find($slip->document_read_id);

            return response()->json([
                'id' => $slip->id,
                'status' => $slip->status,
                'credits_cap' => (string) ($read?->credits_cap ?? '0.0000'),
            ], $slip->wasRecentlyCreated ? 202 : 200);
        });
    }

    /** Asking also moves the reading along, so a reading that is being watched never waits for the scheduler. */
    public function show(Request $request, SlipRead $slip): JsonResponse
    {
        abort_unless($this->slips->mayOpen($slip, $request->user()), 403);

        return response()->json($this->slips->present($slip, $request->user()));
    }

    public function recheck(Request $request, SlipRead $slip): JsonResponse
    {
        abort_unless($this->slips->mayOpen($slip, $request->user()), 403);

        return $this->answer(fn () => response()->json($this->slips->present($this->slips->recheck($slip, $request->user()), $request->user()), 202));
    }

    public function discard(Request $request, SlipRead $slip): JsonResponse
    {
        abort_unless($this->slips->mayOpen($slip, $request->user()), 403);

        return $this->answer(fn () => response()->json($this->slips->present($this->slips->discard($slip, $request->user()), $request->user())));
    }

    /**
     * Runs a step and turns each refusal the reader can raise into its answer: the code, its details
     * and a sentence in the person's language.
     *
     * @param  Closure(): JsonResponse  $step
     */
    private function answer(Closure $step): JsonResponse
    {
        try {
            return $step();
        } catch (SpendRefused|ReadRefused $refused) {
            return response()->json([...$refused->toArray(), 'message' => $refused->getMessage()], $refused->status);
        } catch (ImageRefused $refused) {
            return response()->json([...$refused->toArray(), 'message' => $this->imageWords($refused)], 422);
        } catch (ReaderUnavailable) {
            // Not a 5xx: the request was understood, this server just cannot do it. The platform admins have been told why.
            return response()->json(['code' => 'server_cannot_process', 'message' => __('The server cannot process pictures right now. Nothing was stored. Try again later.')], 409);
        }
    }

    private function imageWords(ImageRefused $refused): string
    {
        $details = $refused->details;

        return match ($refused->reason) {
            ImageRefused::IMAGE_TOO_BIG => __('This picture is too large to read. Make it :width by :height pixels or smaller.', [
                'width' => (int) ($details['fits']['width'] ?? ImageTokens::MAX_EDGE),
                'height' => (int) ($details['fits']['height'] ?? ImageTokens::MAX_EDGE),
            ]),
            ImageRefused::IMAGE_TOO_SMALL => __('This picture is too small to read. Its shorter side must be at least :pixels pixels.', ['pixels' => (int) ($details['min_short_side'] ?? 0)]),
            ImageRefused::IMAGE_MIRRORED => __('This picture is saved mirrored. Take the photo again.'),
            ImageRefused::FILE_TOO_BIG => __('This picture file is too large. Save it at a lower quality and send it again.'),
            default => __('This file is not a picture that can be read. Use a JPG, PNG or WebP picture.'),
        };
    }
}
