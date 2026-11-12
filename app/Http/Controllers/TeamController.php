<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Access\DeviceService;
use App\Services\Access\InvitationService;
use App\Services\Access\TeamAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function __construct(private TeamAccessService $access, private InvitationService $invitations) {}

    public function index(Request $request): View
    {
        $actor = $request->user();

        return view('team.index', [
            // Contractor logins are not team members: they are managed from each contractor's page. A deleted account is nobody's to manage.
            'users' => User::where('organization_id', $actor->organization_id)->whereNot('role', User::CONTRACTOR_ROLE)->whereNull('anonymised_at')->with('customRole')->withCount('assignedProjects')->orderBy('name')->get(),
            'invitations' => TeamInvitation::pending()->latest('id')->get(),
            'roleOptions' => $this->access->roleOptions($actor),
            'projects' => $this->projects(),
            'canAdd' => $this->invitations->hasSeat($actor->organization),
            'invitationRoles' => fn (TeamInvitation $invitation) => $this->invitations->roleLabel($invitation),
            'canManage' => fn (User $user) => $this->access->canManage($actor, $user),
        ]);
    }

    /** Add a member by invitation. They choose their own name and password. */
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();

        abort_unless($this->invitations->hasSeat($actor->organization), 403, __('Your plan user limit has been reached. Upgrade to add more.'));

        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'role' => ['required', 'string', Rule::in(array_keys($this->access->roleOptions($actor)))],
            ...$this->projectRules(required: true),
        ]);

        $issued = $this->invitations->invite($actor, [
            ...$data,
            'limited' => $data['project_access'] === 'chosen',
            'project_ids' => $data['project_ids'] ?? [],
        ]);

        return back()->with([
            'success' => __('Invitation created.'),
            'invite_link' => $issued['link'],
            'invite_email' => $issued['invitation']->email,
            'invite_emailed' => $issued['emailed'],
        ]);
    }

    public function edit(Request $request, User $user, DeviceService $devices): View
    {
        $this->access->assertCanManage($request->user(), $user);

        return view('modules.access.member', [
            'member' => $user->load('customRole'),
            'isSelf' => $user->is($request->user()),
            'roleOptions' => $this->access->roleOptions($request->user()),
            'projects' => $this->projects(),
            'assigned' => $user->assignedProjectIds()->pluck('project_id')->map(fn ($id) => (int) $id)->all(),
            'devices' => $devices->list($user),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->organization_id === $request->user()->organization_id, 404);

        $data = $request->validate([
            'role' => ['required', 'string'],
            ...$this->projectRules(required: false),
        ]);

        $this->access->update($request->user(), $user, $data['role'], $request->boolean('is_active'), isset($data['project_access']) ? [
            'limited' => $data['project_access'] === 'chosen',
            'ids' => array_map('intval', $data['project_ids'] ?? []),
        ] : null);

        return back()->with('success', __('Team member updated.'));
    }

    private function projects()
    {
        return Project::orderBy('name')->get(['id', 'code', 'name']);
    }

    private function projectRules(bool $required): array
    {
        return [
            'project_access' => [$required ? 'required' : 'sometimes', Rule::in(['all', 'chosen'])],
            'project_ids' => ['exclude_unless:project_access,chosen', 'required', 'array', 'min:1'],
            'project_ids.*' => ['exclude_unless:project_access,chosen', 'integer', org_exists('projects')->whereNull('deleted_at')],
        ];
    }
}
