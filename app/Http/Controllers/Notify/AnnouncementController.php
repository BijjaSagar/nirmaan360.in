<?php

namespace App\Http\Controllers\Notify;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Notify\AnnouncementService;
use App\Services\Notify\MoneyMention;
use App\Services\Notify\Notifier;
use App\Support\Money;
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
            // A settings.manage role need not hold money.view: such a viewer sees that a message had figures, not the figures.
            'canSeeMoney' => Money::visible($request->user()),
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

        $saved = match ($announcement->schedule) {
            'now' => __('Announcement sent. Phones get it within a minute, or after quiet hours for people who keep them.'),
            'once' => __('Announcement scheduled for :date.', ['date' => $announcement->scheduled_for?->format('d M Y, H:i')]),
            default => __('Reminder saved. The first one goes on :date.', ['date' => $announcement->scheduled_for?->format('d M Y, H:i')]),
        };

        // Said out loud, so a writer whose "no" was not taken knows who it went to, and why.
        if ($announcement->mentions_money) {
            $saved .= ' '.($data['mentions_money'] === 'no'
                ? __('It looks like it mentions money (":words"), so it goes only to people who may see money. Write it again without the amount for everyone.', [
                    'words' => (string) MoneyMention::evidenceInAnnouncement((string) $data['title'], (string) $data['body'], collect(AnnouncementService::TRANSLATED)
                        ->mapWithKeys(fn (string $locale) => [$locale => ['title' => (string) ($data['title_'.$locale] ?? ''), 'body' => (string) ($data['body_'.$locale] ?? '')]])->all()),
                ])
                : __('It mentions money, so it goes only to people who may see money.'));
        }

        return redirect()->route($this->routes($company)['index'])->with('success', $saved);
    }

    public function cancel(Request $request, Announcement $announcement): RedirectResponse
    {
        $this->ownedBy($request, $announcement);

        $this->announcements->cancel($announcement, $request->user());

        return back()->with('success', __('Stopped. Nothing more will be sent for it, and copies waiting for quiet hours to end are dropped. Phones that already showed it keep it.'));
    }

    /** Sends a failed or partly sent one-off again to the people it did not reach. */
    public function retry(Request $request, Announcement $announcement): RedirectResponse
    {
        $this->ownedBy($request, $announcement);

        $this->announcements->retry($announcement, $request->user());

        return back()->with('success', __('Sending again to the people it did not reach.'));
    }

    /** A company admin reaches only their own company's announcements; anything else does not exist for them. */
    private function ownedBy(Request $request, Announcement $announcement): void
    {
        $company = $this->company($request);

        abort_if($company !== null && $announcement->organization_id !== $company->id, 404);
    }

    /**
     * The picture, for Firebase and the phones, which fetch it without signing in. The token in the
     * address is the only way to it: 40 random letters, not the announcement's id, replaced on Stop.
     */
    public function image(Request $request, string $token): StreamedResponse
    {
        $announcement = Announcement::where('image_token', $token)->first(['id', 'image_path', 'status', 'mentions_money']);

        abort_unless($announcement?->image_path !== null && Storage::exists($announcement->image_path), 404);

        // A message with money: only through a signed address, handed out to people who may see money, and not kept by shared caches.
        if ($announcement->mentions_money) {
            abort_unless($request->hasValidRelativeSignature(), 404);
        }

        $cache = $announcement->mentions_money ? 'private, max-age=300' : 'public, max-age=604800';

        return Storage::response($announcement->image_path, null, ['Cache-Control' => $cache, 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Null on the platform admin's page. */
    private function company(Request $request): ?Organization
    {
        return $request->routeIs('admin.*') ? null : $request->user()->organization;
    }

    /** @return array{index: string, store: string, cancel: string, retry: string} */
    private function routes(?Organization $company): array
    {
        $prefix = $company === null ? 'admin.announcements' : 'notify.announcements';

        return ['index' => $prefix, 'store' => $prefix.'.store', 'cancel' => $prefix.'.cancel', 'retry' => $prefix.'.retry'];
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
