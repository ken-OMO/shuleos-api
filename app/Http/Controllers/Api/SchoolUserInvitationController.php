<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\Administrator\SchoolUserInvitationService;
use Illuminate\Http\Request;

class SchoolUserInvitationController extends BaseApiController
{
    public function __construct(private SchoolUserInvitationService $invitations) {}

    public function index(Request $request)
    {
        return $this->success($this->invitations->index($request->user()));
    }

    public function roles(Request $request)
    {
        return $this->success(['roles' => $this->invitations->roles($request->user())]);
    }

    public function store(Request $request)
    {
        // TenantMiddleware captures client input before overwriting school_id.
        abort_if($request->attributes->get('client_supplied_school_id'), 422, 'School overrides are prohibited.');
        $data = $request->validate([
            'email' => 'required|email|max:100', 'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100', 'role_id' => 'required|uuid',
            'username' => 'prohibited', 'password' => 'prohibited', 'active' => 'prohibited',
        ]);
        foreach (['first_name', 'last_name'] as $field) {
            $data[$field] = trim(strip_tags($data[$field]));
        }
        validator($data, ['first_name' => 'required', 'last_name' => 'required'])->validate();

        return $this->created($this->invitations->invite($request->user(), $data));
    }

    public function resend(Request $request, string $user)
    {
        abort_if($request->attributes->get('client_supplied_school_id'), 422, 'School overrides are prohibited.');

        return $this->success($this->invitations->resend($request->user(), $user));
    }
}
