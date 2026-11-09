<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function index(Request $request): View
    {
        return view('team.index', [
            'users' => User::where('organization_id', $request->user()->organization_id)->orderBy('name')->get(),
            'roles' => config('buildos.roles'),
            'canAdd' => $request->user()->organization->canAddUser(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->organization->canAddUser(), 403, __('Your plan user limit has been reached. Upgrade to add more.'));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'role' => ['required', Rule::in(array_keys(config('buildos.roles')))],
            'password' => ['required', Password::min(8)],
        ]);

        User::create([...$data, 'organization_id' => $request->user()->organization_id]);

        return back()->with('success', __('Team member added.'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->organization_id === $request->user()->organization_id, 404);

        $data = $request->validate([
            'role' => ['required', Rule::in(array_keys(config('buildos.roles')))],
        ]);
        $active = $request->boolean('is_active');

        if ($user->is($request->user()) && (! $active || $data['role'] !== $user->role)) {
            throw ValidationException::withMessages(['role' => __('You cannot change your own role or deactivate yourself.')]);
        }

        $user->update([...$data, 'is_active' => $active]);

        return back()->with('success', __('Team member updated.'));
    }
}
