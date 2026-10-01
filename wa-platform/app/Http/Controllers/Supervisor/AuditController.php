<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;

class AuditController extends Controller
{
    public function index()
    {
        $logs = AuditLog::with('user')->latest('created_at')->paginate(50);

        return view('supervisor.audit', compact('logs'));
    }
}
