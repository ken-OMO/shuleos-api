<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * Display a listing of audit logs.
     */
    public function index(Request $request)
    {
        $query = AuditLog::query()
            ->with([
                'school',
                'user',
            ]);

        if ($request->filled('school_id')) {
            $query->where('school_id', $request->school_id);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('module')) {
            $query->where('module', $request->module);
        }

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('table_name')) {
            $query->where('table_name', $request->table_name);
        }

        if ($request->filled('from_date')) {
            $query->whereDate(
                'created_at',
                '>=',
                $request->from_date
            );
        }

        if ($request->filled('to_date')) {
            $query->whereDate(
                'created_at',
                '<=',
                $request->to_date
            );
        }

        $logs = $query
            ->latest('created_at')
            ->paginate(25);

        return AuditLogResource::collection($logs);
    }

    /**
     * Display a single audit log.
     */
    public function show(AuditLog $auditLog)
    {
        $auditLog->load([

            'school',

            'user',

        ]);

        return new AuditLogResource($auditLog);
    }

    /**
     * Audit logs cannot be created manually.
     */
    public function store(Request $request)
    {
        return response()->json([

            'success' => false,

            'message' => 'Audit logs are created automatically by the system.'

        ], 405);
    }

    /**
     * Audit logs cannot be updated.
     */
    public function update(Request $request, AuditLog $auditLog)
    {
        return response()->json([

            'success' => false,

            'message' => 'Audit logs cannot be updated.'

        ], 405);
    }

    /**
     * Audit logs cannot be deleted.
     */
    public function destroy(AuditLog $auditLog)
    {
        return response()->json([

            'success' => false,

            'message' => 'Audit logs cannot be deleted.'

        ], 405);
    }
}
