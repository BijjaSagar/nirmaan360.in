<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Services\Crm\ActivityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function __construct(private ActivityService $service) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->service->rules());

        $this->service->logFor($request->user(), $data['subject_type'], (int) $data['subject_id'], $data['kind'], $data['body']);

        return back()->with('success', __('Note saved.'));
    }
}
