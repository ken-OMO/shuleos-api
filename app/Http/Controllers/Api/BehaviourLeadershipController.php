<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\AttendanceRiskFlagResource;
use App\Http\Resources\BehaviourAnalyticsResource;
use App\Http\Resources\BehaviourRecognitionResource;
use App\Http\Resources\DisciplineCaseResource;
use App\Models\AttendanceRiskFlag;
use App\Models\BehaviourRecognition;
use App\Services\Attendance\AttendanceIntelligenceService;
use App\Services\Behaviour\BehaviourAnalyticsService;
use App\Services\Behaviour\BehaviourService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BehaviourLeadershipController extends BaseApiController
{
    public function __construct(private BehaviourService $s, private BehaviourAnalyticsService $analytics, private AttendanceIntelligenceService $risk) {}

    public function index()
    {
        return $this->analytics();
    }

    public function analytics()
    {
        return $this->success(new BehaviourAnalyticsResource($this->analytics->summary(auth()->user())));
    }

    public function cases()
    {
        return $this->success(DisciplineCaseResource::collection($this->s->leadershipCases(auth()->user())->with('category', 'actions')->paginate(30)));
    }

    public function show(string $case)
    {
        return $this->success(new DisciplineCaseResource($this->s->leadershipCases(auth()->user())->whereKey($case)->with('category', 'actions')->firstOrFail()));
    }

    public function transition(Request $r, string $case, string $to)
    {
        $d = $r->validate(['reason' => 'nullable|string|max:4000']);

        return $this->success(new DisciplineCaseResource($this->s->transition(auth()->user(), $case, $to, $d['reason'] ?? null)));
    }

    public function action(Request $r, string $case)
    {
        $d = $r->validate(['action_type' => 'required|string|max:100', 'remarks' => 'nullable|string', 'assigned_to' => 'nullable|uuid', 'due_at' => 'nullable|date', 'follow_up_required' => 'sometimes|boolean', 'follow_up_at' => 'nullable|date', 'visible_to_learner' => 'sometimes|boolean', 'visible_to_parent' => 'sometimes|boolean']);

        return $this->created($this->s->action(auth()->user(), $case, $d, true));
    }

    public function approve(string $recognition)
    {
        $r = BehaviourRecognition::whereKey($recognition)->where('school_id', auth()->user()->school_id)->where('status', 'draft')->firstOrFail();
        $r->update(['status' => 'published', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        $this->s->audit(auth()->user(), 'recognition_approved', $r->learner_id, recognition: $r->id);

        return $this->success(new BehaviourRecognitionResource($r));
    }

    public function referral(Request $r)
    {
        $d = $r->validate(['learner_id' => 'required|uuid', 'discipline_case_id' => 'nullable|uuid', 'attendance_alert_id' => 'nullable|uuid', 'assigned_to' => 'nullable|uuid', 'referral_reason' => 'required|string', 'priority' => 'required|in:low,medium,high,urgent']);
        abort_unless(DB::table('learners')->where('id', $d['learner_id'])->where('school_id', auth()->user()->school_id)->exists(), 422);
        $id = (string) Str::uuid();
        DB::table('behaviour_counselling_referrals')->insert($d + ['id' => $id, 'school_id' => auth()->user()->school_id, 'referred_by' => auth()->id(), 'status' => 'referred', 'confidential' => true, 'referred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->s->audit(auth()->user(), 'counselling_referral_created', $d['learner_id'], referral: $id);

        return $this->created(['id' => $id, 'status' => 'referred', 'priority' => $d['priority']]);
    }

    public function indicators()
    {
        return $this->success($this->analytics->indicators(auth()->user()));
    }

    public function risks()
    {
        return $this->success(AttendanceRiskFlagResource::collection(AttendanceRiskFlag::where('school_id', auth()->user()->school_id)->paginate(30)));
    }

    public function riskUpdate(Request $r, string $flag, string $to)
    {
        $d = $r->validate(['notes' => 'required|string|max:4000']);

        return $this->success(new AttendanceRiskFlagResource($this->risk->update(auth()->user(), $flag, $to, $d['notes'])));
    }
}
