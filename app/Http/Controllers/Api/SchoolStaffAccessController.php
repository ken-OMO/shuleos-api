<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\Administrator\SchoolStaffAccessService;
use App\Services\Auth\AuthContextService;
use Illuminate\Http\Request;

final class SchoolStaffAccessController extends BaseApiController
{
    public function index(Request $request, SchoolStaffAccessService $staff)
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1|max:100000']);

        return $this->success($staff->index($request->user(), (int) ($data['page'] ?? 1)));
    }

    public function change(Request $request, string $user, SchoolStaffAccessService $staff)
    {
        abort_if($request->attributes->get('client_supplied_school_id'), 422, 'School overrides are prohibited.');
        $data = $request->validate(['role' => 'required|in:Headteacher,School Secretary', 'assigned' => 'required|boolean',
            'permissions' => 'prohibited', 'role_id' => 'prohibited']);

        return $this->success($staff->change($request->user(), $user, $data['role'], $data['assigned']));
    }

    public function teacherProfile(Request $request, string $user, SchoolStaffAccessService $staff)
    {
        abort_if($request->attributes->get('client_supplied_school_id'), 422, 'School overrides are prohibited.');
        $data = $request->validate([
            'staff_no' => 'required|string|max:50',
            'tsc_no' => 'required|string|max:50',
        ]);

        return $this->success($staff->setUpTeacher($request->user(), $user, $data));
    }

    public function office(Request $request, AuthContextService $auth)
    {
        $context = $auth->resolve($request->user());
        abort_unless($context['scope'] === 'school' && in_array('School Secretary', $context['roles'], true)
            && $auth->hasPermission($request->user(), 'view_school_office_summary'), 403);

        return $this->success(['school_id' => $context['school_id'], 'school_name' => $context['school']['name'],
            'role' => 'School Secretary', 'business_forms_available' => false]);
    }
}
