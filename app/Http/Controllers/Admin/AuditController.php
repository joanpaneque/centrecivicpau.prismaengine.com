<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:60'],
            'user' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $devices = Device::query()->pluck('name', 'id');

        return Inertia::render('admin/Audit', [
            'logs' => AuditLog::query()
                ->with('user:id,name')
                ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', $a.'%'))
                ->when($filters['user'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
                ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
                ->latest('id')
                ->paginate(50)
                ->withQueryString()
                ->through(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'user' => $log->user?->name,
                    'device' => $log->device_id ? $devices->get($log->device_id) : null,
                    'subject' => $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : null,
                    'data' => $log->data,
                    'reason' => $log->reason,
                    'createdAt' => $log->created_at->toIso8601String(),
                ]),
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }
}
