<?php

namespace App\Http\Controllers\DocumentReading;

use App\Http\Controllers\Controller;
use App\Models\ReaderTrial;
use App\Models\ReaderTrialPage;
use App\Services\DocumentReading\ImageRefused;
use App\Services\DocumentReading\ImageTokens;
use App\Services\DocumentReading\ReaderTrials;
use App\Services\DocumentReading\ReaderUnavailable;
use App\Services\DocumentReading\ReadRefused;
use App\Services\DocumentReading\TrialFiles;
use App\Services\DocumentReading\TrialResults;
use App\Services\DocumentReading\TrialScore;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The platform admin's dry runs: make one, upload its pages or paste its text, start it, watch
 * it, mark what was read wrongly and take the counts away. Platform staff only.
 *
 * A dry run takes its material from this screen alone. No route here reads a company's file.
 */
class ReaderTrialController extends Controller
{
    use ChecksParent;

    public function __construct(private ReaderTrials $trials) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'allowed' => ['accepted'],
            'kind' => ['nullable', 'string', 'max:24'],
            'pass' => ['nullable', 'string', 'max:16'],
            'material' => ['nullable', Rule::in([ReaderTrial::PICTURES, ReaderTrial::TEXT])],
            'purpose' => ['nullable', 'string', 'max:24'],
            'subject_type' => ['nullable', 'string', 'max:40'],
            'model' => ['nullable', 'string', 'max:60'],
            'effort' => ['nullable', 'string', 'max:10'],
            'max_tokens' => ['nullable', 'integer'],
            'timeout' => ['nullable', 'integer'],
            'pages_total' => ['nullable', 'integer'],
            'language' => ['nullable', 'string', 'max:5'],
        ], ['allowed.accepted' => __('Tick the box to confirm that you are allowed to use this document for testing.')]);

        $trial = $this->trials->create($request->user(), [...$data, 'allowed' => true]);

        return redirect()->route('ai-reader.admin.trials.show', $trial)->with('success', __('The dry run is ready for its pages.'));
    }

    public function show(ReaderTrial $trial, TrialResults $results): View
    {
        abort_if($trial->status === ReaderTrial::DISCARDED, 404);

        $status = $this->trials->status($trial);

        return view('modules.document_reading.admin.trial', [
            'trial' => $trial->refresh(),
            'status' => $status,
            'results' => $results->of($trial),
            'mostTextChars' => ReaderTrials::MOST_TEXT_CHARS,
        ]);
    }

    /** Asking also moves the dry run along, so one that is being watched never waits for the scheduler. */
    public function status(ReaderTrial $trial): JsonResponse
    {
        return response()->json($this->trials->status($trial));
    }

    public function storePage(Request $request, ReaderTrial $trial): JsonResponse
    {
        $data = $request->validate([
            'page_no' => ['required', 'integer', 'min:1'],
            'image' => ['required', 'file', 'max:'.(int) config('modules.document_reading.upload.page_kb')],
        ]);

        return $this->answer(function () use ($request, $trial, $data) {
            $page = $this->trials->storePicture($trial, (int) $data['page_no'], (string) file_get_contents($request->file('image')->getRealPath()), $request->user());

            return response()->json([
                'id' => $page->id,
                'page_no' => (int) $page->page_no,
                'width' => (int) $page->image_width,
                'height' => (int) $page->image_height,
                'tokens' => ImageTokens::count((int) $page->image_width, (int) $page->image_height),
            ], 201);
        });
    }

    public function storeText(Request $request, ReaderTrial $trial): BaseResponse
    {
        $data = $request->validate([
            'page_no' => ['nullable', 'integer', 'min:1'],
            'text' => ['required', 'string'],
        ]);

        try {
            $this->trials->storeText($trial, (int) ($data['page_no'] ?? 1), $data['text'], $request->user());
        } catch (ReadRefused $refused) {
            return $this->refusal($request, $refused);
        }

        return $request->expectsJson()
            ? response()->json(['stored' => true], 201)
            : back()->with('success', __('The text is stored. Press Start to send it.'));
    }

    public function start(Request $request, ReaderTrial $trial): BaseResponse
    {
        try {
            $this->trials->start($trial, $request->user());
        } catch (ReadRefused $refused) {
            return $this->refusal($request, $refused);
        }

        return $request->expectsJson()
            ? response()->json($this->trials->body($trial->refresh()), 202)
            : back()->with('success', __('The dry run has started. This page shows each answer as it arrives.'));
    }

    public function mark(Request $request, ReaderTrial $trial): JsonResponse
    {
        $data = $request->validate([
            'page_no' => ['required', 'integer', 'min:1'],
            'pass' => ['nullable', 'string', 'max:16'],
            'row' => ['nullable', 'string', 'max:60', 'regex:/^\d+(\.\d+)*$/'],
            'field' => ['required', 'string', 'max:200'],
            'wrong' => ['required', 'boolean'],
        ]);

        return $this->answer(fn () => response()->json(['score' => $this->trials->mark(
            $trial, (int) $data['page_no'], $data['pass'] ?? null, (string) ($data['row'] ?? ''), $data['field'], $request->boolean('wrong'),
        )]));
    }

    /** The counts of the score as a spreadsheet file. Counts only: no word of any document is in it. */
    public function scores(ReaderTrial $trial): StreamedResponse
    {
        abort_if($trial->status === ReaderTrial::DISCARDED, 404);

        $rows = TrialScore::rows($trial);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            foreach ($rows as $row) {
                fputcsv($out, $row, ',', '"', '');
            }

            fclose($out);
        }, "dry-run-{$trial->id}-scores.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The stored page picture of a dry run, exactly as it goes to the reader. Gone once the files are deleted. */
    public function image(Request $request, ReaderTrial $trial, ReaderTrialPage $page): BaseResponse
    {
        $this->assertChildOf($page, $trial);

        abort_unless($trial->purged_at === null && TrialFiles::owns($trial, $page->image_path), 404);

        $headers = ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-cache', 'X-Content-Type-Options' => 'nosniff'];
        $tag = filled($page->image_sha256) ? '"'.$page->image_sha256.'"' : null;

        if ($tag !== null) {
            $headers['ETag'] = $tag;

            if (in_array($tag, $request->getETags(), true)) {
                return new Response('', 304, $headers);
            }
        }

        abort_unless(Storage::exists($page->image_path), 404);

        return Storage::response($page->image_path, "dry-run-page-{$page->page_no}.jpg", $headers, 'inline');
    }

    public function destroy(Request $request, ReaderTrial $trial): RedirectResponse
    {
        $this->trials->discard($trial, $request->user());

        return redirect()->route('ai-reader.admin.index')->with('success', __('The dry run is deleted with its files. Its tokens and cost stay in the totals.'));
    }

    /**
     * Runs a step that answers JSON and turns each refusal the services can raise into its answer.
     *
     * @param  Closure(): BaseResponse  $step
     */
    private function answer(Closure $step): BaseResponse
    {
        try {
            return $step();
        } catch (ReadRefused $refused) {
            return response()->json([...$refused->toArray(), 'message' => $refused->getMessage()], $refused->status);
        } catch (ImageRefused $refused) {
            return response()->json([...$refused->toArray(), 'message' => $this->imageWords($refused)], 422);
        } catch (ReaderUnavailable $unavailable) {
            // Not a 5xx: the request was understood, this server just cannot do it.
            return response()->json(['code' => 'server_cannot_process', 'reason' => $unavailable->reason, 'message' => __('This server cannot redraw pictures, so no page can be stored. The PHP image functions (GD) are missing or failed.')], 409);
        }
    }

    /** A refusal of a form's step: the sentence under the form on the web, the code as well for a script. */
    private function refusal(Request $request, ReadRefused $refused): BaseResponse
    {
        return $request->expectsJson()
            ? response()->json([...$refused->toArray(), 'message' => $refused->getMessage()], $refused->status)
            : back()->withErrors(['trial' => $refused->getMessage()]);
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
