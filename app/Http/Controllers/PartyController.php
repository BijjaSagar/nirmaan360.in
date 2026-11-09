<?php

namespace App\Http\Controllers;

use App\Models\Party;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PartyController extends Controller
{
    public function index(Request $request): View
    {
        $type = $request->query('type');

        return view('parties.index', [
            'parties' => Party::when($type, fn ($q) => $q->where('type', $type))->orderBy('name')->paginate(30)->withQueryString(),
            'type' => $type,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Party::create($this->validated($request));

        return back()->with('success', __('Contact added.'));
    }

    public function update(Request $request, Party $party): RedirectResponse
    {
        $party->update($this->validated($request));

        return back()->with('success', __('Contact updated.'));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(config('buildos.party_types'))],
            'name' => ['required', 'string', 'max:160'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:190'],
            'gstin' => ['nullable', 'string', 'size:15'],
            'pan' => ['nullable', 'string', 'size:10'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
