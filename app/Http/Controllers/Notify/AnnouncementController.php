<?php

namespace App\Http\Controllers\Notify;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Notify\AnnouncementService;
use App\Services\Notify\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The page to write, aim and schedule an announcement, with what was sent before.
 *
 * Two doors to the same page: admin.announcements for platform staff (EnsurePlatformAdmin), who
 * write to any company, and notify.announcements for a company's admin (settings.manage), who
 * writes to their own company and sees only its announcements.
 */
class AnnouncementController extends Controller
{
    public function __construct(private AnnouncementService $announcements) {}

    public function index(Request $request, Notifier $notifier): View
    {
        $company = $this->company($request);

        $history = Announcement::query()
            ->when($company, fn ($query, Organization $company) => $query->forCompany($company->id))
            ->with(['author:id,name', 'organization:id,name', 'targetOrganization:id,name', 'project:id,name'])
            ->latest('id')
            ->limit(50)
            ->get();

        return view('modules.notify.announcements', [
            'company' => $company,
            'history' => $history,
            'service' => $this->announcements,
            'pushConnected' => $notifier->connected('push'),
            'companies' => $company === null ? Organization::orderBy('name')->get(['id', 'name']) : collect(),
            'projects' => $this->projects($company),
            'roles' => $this->announcements->roleOptions($company),
            'weekdays' => $this->announcements->weekdays(),
            'clientUuid' => (string) Str::uuid(),
            'routes' => $this->routes($company),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = $this->company($request);
        $data = $request->validate($this->announcements->rules($company));

        $announcement = $this->announcements->create($request->user(), $data, $request->file('image'), $company);

        return redirect()->route($this->routes($company)['index'])->with('success', match ($announcement->schedule) {
            'now' => __('Announcement sent. Phones get it within a minute, or after quiet hours for people who keep them.'),
            'once' => __('Announcement scheduled for :date.', ['date' => $announcement->next_run_at?->format('d M Y, H:i')]),
            default => __('Reminder saved. The first one goes on :date.', ['date' => $announcement->next_run_at?->format('d M Y, H:i')]),
        });
    }

    public function cancel(Request $request, Announcement $announcement): RedirectResponse
    {
        $company = $this->company($request);

        // A company admin reaches only their own company's announcements; anything else does not exist for them.
        abort_if($company !== null && $announcement->organization_id !== $company->id, 404);

        $this->announcements->cancel($announcement, $request->user());

        return back()->with('success', __('Stopped. Nothing more will be sent for it.'));
    }

    /**
     * The picture, for Firebase and the phones, which fetch it without signing in. The token in the
     * address is the only way to it: 40 random letters, not the announcement's id.
     */
    public function image(string $token): StreamedResponse
    {
        $announcement = Announcement::where('image_token', $token)->first(['id', 'image_path', 'status']);

        abort_unless($announcement?->image_path !== null && Storage::exists($announcement->image_path), 404);

        return Storage::response($announcement->image_path, null, ['Cache-Control' => 'public, max-age=604800', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Null on the platform admin's page. */
    private function company(Request $request): ?Organization
    {
        return $request->routeIs('admin.*') ? null : $request->user()->organization;
    }

    /** @return array{index: string, store: string, cancel: string} */
    private function routes(?Organization $company): array
    {
        $prefix = $company === null ? 'admin.announcements' : 'notify.announcements';

        return ['index' => $prefix, 'store' => $prefix.'.store', 'cancel' => $prefix.'.cancel'];
    }

    private function projects(?Organization $company)
    {
        if ($company !== null) {
            // The tenant scope keeps this to the admin's own company.
            return Project::orderBy('name')->get(['id', 'name', 'code', 'organization_id']);
        }

        return Project::withoutGlobalScopes()->with('organization:id,name')->orderBy('organization_id')->orderBy('name')->get(['id', 'name', 'code', 'organization_id']);
    }
}
