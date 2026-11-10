<?php

namespace App\Http\Controllers\Notify;

use App\Http\Controllers\Controller;
use App\Services\ContractorPortal\ContractorPortalService;
use App\Services\Notify\AnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The field app's list of announcements: what was sent to the signed-in person, newest first.
 * Staff call GET /announcements; a contractor login calls GET /contractor-portal/announcements.
 */
class AnnouncementApiController extends Controller
{
    public function __construct(private AnnouncementService $announcements, private ContractorPortalService $portal) {}

    public function index(Request $request): JsonResponse
    {
        // A contractor login must still lead to a subcontractor, as on every other portal page.
        if ($request->user()->isContractor()) {
            $this->portal->party($request->user());
        }

        // The app may show another language than the account's. ?locale= asks for the text in that one.
        $locale = $request->query('locale');
        $locale = is_string($locale) && array_key_exists($locale, config('buildos.locales')) ? $locale : app()->getLocale();

        return response()->json($this->announcements->forUser($request->user(), $locale));
    }
}
