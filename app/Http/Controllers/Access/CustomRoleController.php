<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\CustomRole;
use App\Services\Access\CustomRoleService;
use App\Services\Access\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomRoleController extends Controller
{
    public function __construct(private CustomRoleService $service, private PermissionCatalog $catalog) {}

    public function index(): View
    {
        return view('modules.access.roles', [
            'roles' => CustomRole::withCount('users')->orderBy('name')->get(),
            // Portal roles, such as the contractor login, are not team roles and are given elsewhere.
            'builtIn' => array_filter(config('buildos.roles'), fn (array $role) => ! ($role['portal'] ?? false)),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, new CustomRole(['permissions' => []]));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->service->create($request->user(), $request->validate($this->service->rules($request->user())));

        return redirect()->route('team.roles.index')->with('success', __('Role created.'));
    }

    public function edit(Request $request, CustomRole $customRole): View
    {
        $this->service->assertCanEdit($request->user(), $customRole);

        return $this->form($request, $customRole);
    }

    public function update(Request $request, CustomRole $customRole): RedirectResponse
    {
        $this->service->assertCanEdit($request->user(), $customRole);

        $this->service->update($request->user(), $customRole, $request->validate($this->service->rules($request->user(), $customRole)));

        return redirect()->route('team.roles.index')->with('success', __('Role updated.'));
    }

    public function destroy(Request $request, CustomRole $customRole): RedirectResponse
    {
        $this->service->delete($request->user(), $customRole);

        return redirect()->route('team.roles.index')->with('success', __('Role deleted.'));
    }

    private function form(Request $request, CustomRole $role): View
    {
        return view('modules.access.role-form', [
            'role' => $role,
            'groups' => $this->catalog->grantableBy($request->user()),
            'catalog' => $this->catalog,
            'approvalRoles' => $this->service->approvalRoles(),
        ]);
    }
}
