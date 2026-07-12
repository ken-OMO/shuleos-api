<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Models\Learner;
use App\Models\ReportCardAccessOverride;
use App\Models\SchoolSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ParentPortalAdminController extends BaseApiController
{
    private const POLICIES = ['open', 'view_only_when_balance', 'download_only_when_cleared', 'fully_restricted_when_balance', 'admin_approval', 'balance_threshold'];

    private function school(Request $r): string
    {
        $id = $r->attributes->get('tenant_school_id');
        abort_if(! $id, 403, 'School context not found.');

        return (string) $id;
    }

    public function policy(Request $r)
    {
        return $this->success(SchoolSettings::where('school_id', $this->school($r))->first());
    }

    public function updatePolicy(Request $r)
    {
        $v = $r->validate(['report_card_fee_policy' => 'required|in:'.implode(',', self::POLICIES), 'report_card_balance_threshold' => 'sometimes|numeric|min:0', 'report_card_restriction_message' => 'sometimes|nullable|string', 'report_card_allow_admin_override' => 'sometimes|boolean']);
        $x = SchoolSettings::where('school_id', $this->school($r))->firstOrFail();
        $x->update($v);

        return $this->success($x, 'Policy updated.');
    }

    public function overrides(Request $r)
    {
        return $this->success(ReportCardAccessOverride::current()->where('school_id', $this->school($r))->latest()->paginate(20));
    }

    public function createOverride(Request $r)
    {
        $v = $r->validate(['learner_id' => 'required|uuid|exists:learners,id', 'exam_id' => 'sometimes|nullable|uuid|exists:exams,id', 'report_card_id' => 'sometimes|nullable|uuid|exists:report_cards,id', 'access_scope' => 'required|in:view,download,both', 'access_allowed' => 'sometimes|boolean', 'reason' => 'sometimes|nullable|string', 'expires_at' => 'sometimes|nullable|date|after:now']);
        $school = $this->school($r);
        abort_unless(Learner::whereKey($v['learner_id'])->where('school_id', $school)->exists(), 403);
        $x = ReportCardAccessOverride::create($v + ['id' => (string) Str::uuid(), 'school_id' => $school, 'approved_by' => auth()->id()]);

        return $this->created($x, 'Override created.');
    }

    public function revokeOverride(Request $r, string $id)
    {
        $x = ReportCardAccessOverride::current()->where('school_id', $this->school($r))->findOrFail($id);
        $x->update(['is_deleted' => true, 'deleted_at' => now(), 'deleted_by' => auth()->id()]);

        return $this->success(null, 'Override revoked.');
    }
}
