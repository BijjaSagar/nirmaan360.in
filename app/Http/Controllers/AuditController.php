<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function __invoke(): View
    {
        return view('settings.audit', ['logs' => AuditLog::with('user')->latest('id')->paginate(50)]);
    }
}
