<?php

namespace App\Http\Controllers\DocumentReading;

use App\Http\Controllers\Controller;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Services\DocumentReading\ImageRefused;
use App\Services\DocumentReading\ImageTokens;
use App\Services\DocumentReading\ReadAccess;
use App\Services\DocumentReading\ReadDesk;
use App\Services\DocumentReading\ReaderUnavailable;
use App\Services\DocumentReading\ReadFiles;
use App\Services\DocumentReading\ReadIntake;
use App\Services\DocumentReading\ReadRefused;
use App\Services\DocumentReading\ReadStatus;
use App\Services\DocumentReading\SpendRefused;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * The steps every kind of read shares, for the browser and for the phone: upload pages, ask the
 * price, start, watch, continue, read a page again, cancel, and fetch a stored page picture. Each
 * feature creates its reads through its own route and reviews them on its own screens.
 *
 * Every answer is JSON, on the web as well: these routes are called by the capture script, not by
 * forms. A step that cannot be taken answers with a code, the details that go with it and a sentence
 * in the person's language. Who may open a read is decided for each read by ReadAccess, because it
 * depends on the read's kind.
 */
class DocumentReadController extends Controller
{
    use ChecksParent;

    /** An amount of credits: up to six digits, and up to four after the point. */
    private const CREDITS = 'regex:/^\d{1,6}(\.\d{1,4})?$/';

    public function __construct(private ReadDesk $desk, private ReadIntake $intake, private ReadStatus $status) {}

    public function storePage(Request $request, DocumentRead $read): JsonResponse
    {
        $kind = ReadAccess::assert($read, $request->user());

        $data = $request->validate([
            'page_no' => ['required', 'integer', 'min:1'],
            'part' => ['nullable', Rule::in(ReadIntake::PARTS)],
            'image' => ['required', 'file', 'max:'.(int) config('modules.document_reading.upload.page_kb')],
        ]);

        return $this->answer(function () use ($request, $read, $kind, $data) {
            $page = $this->intake->storePage($read, $kind, (int) $data['page_no'], $data['part'] ?? ReadIntake::FULL, (string) file_get_contents($request->file('image')->getRealPath()));

            return response()->json([
                'id' => $page->id,
                'page_no' => (int) $page->page_no,
                'part' => $page->part,
                'width' => (int) $page->image_width,
                'height' => (int) $page->image_height,
                'tokens' => ImageTokens::count((int) $page->image_width, (int) $page->image_height),
            ], 201);
        });
    }

    public function storeSource(Request $request, DocumentRead $read): BaseResponse
    {
        ReadAccess::assert($read, $request->user());
        abort_unless((bool) config("modules.document_reading.kinds.{$read->kind}.keeps_source", false), 404);

        $request->validate(['file' => ['required', 'file', 'max:'.(int) config('modules.document_reading.upload.source_kb')]]);

        return $this->answer(function () use ($request, $read) {
            $file = $request->file('file');
            $this->intake->storeSource($read, (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName());

            return response()->noContent();
        });
    }

    public function quote(Request $request, DocumentRead $read): JsonResponse
    {
        ReadAccess::assert($read, $request->user());
        [$from, $to] = $this->range($request->validate($this->rangeRules()));

        return $this->answer(fn () => response()->json($this->desk->quote($read, $request->user(), $from, $to)));
    }

    public function start(Request $request, DocumentRead $read): JsonResponse
    {
        ReadAccess::assert($read, $request->user());

        $data = $request->validate([
            ...$this->rangeRules(),
            'cap' => ['nullable', 'numeric', self::CREDITS],
            'skip_pages' => ['nullable', 'array', 'max:500'],
            'skip_pages.*' => ['integer', 'min:1'],
        ]);

        [$from, $to] = $this->range($data);

        return $this->answer(fn () => $this->started($this->desk->start(
            $read, $request->user(), $data['cap'] ?? null, $from, $to, array_map(intval(...), $data['skip_pages'] ?? []),
        )));
    }

    /** Asking also moves the read along, so a read that is being watched never waits for the scheduler. */
    public function status(Request $request, DocumentRead $read): JsonResponse
    {
        ReadAccess::assert($read, $request->user());

        return response()->json($this->status->of($read));
    }

    public function cancel(Request $request, DocumentRead $read): JsonResponse
    {
        ReadAccess::assert($read, $request->user());

        return $this->answer(fn () => response()->json($this->status->body($this->desk->cancel($read, $request->user()))));
    }

    public function resume(Request $request, DocumentRead $read): JsonResponse
    {
        ReadAccess::assert($read, $request->user());
        $data = $request->validate(['cap' => ['nullable', 'numeric', self::CREDITS]]);

        return $this->answer(fn () => $this->started($this->desk->resume($read, $request->user(), $data['cap'] ?? null)));
    }

    public function retry(Request $request, DocumentRead $read, DocumentReadPage $page): JsonResponse
    {
        $this->assertChildOf($page, $read);
        ReadAccess::assert($read, $request->user());
        $data = $request->validate(['stronger' => ['nullable', 'boolean'], 'cap' => ['nullable', 'numeric', self::CREDITS]]);

        return $this->answer(fn () => $this->started($this->desk->retry($read, $page, $request->user(), $request->boolean('stronger'), $data['cap'] ?? null)));
    }

    public function split(Request $request, DocumentRead $read, DocumentReadPage $page): JsonResponse
    {
        $this->assertChildOf($page, $read);
        ReadAccess::assert($read, $request->user());
        $data = $request->validate(['cap' => ['nullable', 'numeric', self::CREDITS]]);

        return $this->answer(fn () => $this->started($this->desk->split($read, $page, $request->user(), $data['cap'] ?? null)));
    }

    /** The stored page picture, exactly as it goes to the reader. Gone once the read's files are deleted. */
    public function image(Request $request, DocumentRead $read, DocumentReadPage $page): BaseResponse
    {
        $this->assertChildOf($page, $read);
        ReadAccess::assert($read, $request->user());

        abort_unless($read->files_purged_at === null && ReadFiles::owns($read, $page->image_path), 404);

        // The picture of a page can be replaced until the read starts, so the browser asks each time and is told when nothing changed.
        $headers = ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-cache', 'X-Content-Type-Options' => 'nosniff'];
        $tag = filled($page->image_sha256) ? '"'.$page->image_sha256.'"' : null;

        if ($tag !== null) {
            $headers['ETag'] = $tag;

            if (in_array($tag, $request->getETags(), true)) {
                return new Response('', 304, $headers);
            }
        }

        abort_unless(Storage::exists($page->image_path), 404);

        return Storage::response($page->image_path, "page-{$page->page_no}-{$page->part}.jpg", $headers, 'inline');
    }

    /** A step that put the read to work answers 202 with where the read stands now. */
    private function started(DocumentRead $read): JsonResponse
    {
        return response()->json($this->status->body($read), 202);
    }

    /**
     * Runs a step and turns each refusal the services can raise into its answer: the code, its
     * details and a sentence in the person's language.
     *
     * @param  Closure(): BaseResponse  $step
     */
    private function answer(Closure $step): BaseResponse
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

    /** @return array<string, list<string>> */
    private function rangeRules(): array
    {
        return [
            'page_from' => ['nullable', 'integer', 'min:1'],
            'page_to' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?int, 1: ?int} The first and the last page asked for; null where the request left it open.
     */
    private function range(array $data): array
    {
        $from = isset($data['page_from']) ? (int) $data['page_from'] : null;
        $to = isset($data['page_to']) ? (int) $data['page_to'] : null;

        if ($from !== null && $to !== null && $to < $from) {
            throw ValidationException::withMessages(['page_to' => [__('The last page cannot come before the first page.')]]);
        }

        return [$from, $to];
    }
}
