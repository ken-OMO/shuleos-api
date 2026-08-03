<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\LearnerPortal\LearnerAccountService;
use Illuminate\Http\Request;

class LearnerPortalAdminController extends BaseApiController
{
    public function __construct(private readonly LearnerAccountService $s) {}

    private function school(Request $r): string
    {
        $id = $r->attributes->get('tenant_school_id');
        abort_if(! $id, 403);

        return (string) $id;
    }

    public function create(Request $r, string $learner)
    {
        $v = $r->validate(['username' => 'sometimes|string|max:100', 'password' => 'sometimes|string|min:10']);

        return $this->created($this->s->create($this->school($r), $learner, $v), 'Learner account created.');
    }

    public function status(Request $r, string $learner)
    {
        $v = $r->validate(['enabled' => 'required|boolean']);

        return $this->success($this->s->status($this->school($r), $learner, $v['enabled']));
    }

    public function reset(Request $r, string $learner)
    {
        $v = $r->validate(['password' => 'sometimes|string|min:10']);

        return $this->success($this->s->reset($this->school($r), $learner, $v['password'] ?? null), 'Temporary password reset.');
    }
}
