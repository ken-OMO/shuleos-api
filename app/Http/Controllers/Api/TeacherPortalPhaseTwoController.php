<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\MarkCorrectionRequestResource;
use App\Http\Resources\MarkEntryBatchResource;
use App\Http\Resources\TeacherAttachmentResource;
use App\Http\Resources\TeacherPortalSafeResource;
use App\Http\Resources\TeacherPushDeliveryResource;
use App\Http\Resources\TeacherSyncConflictResource;
use App\Http\Resources\TeacherTaskResource;
use App\Http\Resources\TeacherWorkflowHistoryResource;
use App\Http\Resources\TeacherWorkflowResource;
use App\Services\TeacherPortal\MarkEntryBatchService;
use App\Services\TeacherPortal\MarkModerationService;
use App\Services\TeacherPortal\TeacherDeviceService;
use App\Services\TeacherPortal\TeacherHodScopeService;
use App\Services\TeacherPortal\TeacherPortalAccessService;
use App\Services\TeacherPortal\TeacherPortalAttachmentService;
use App\Services\TeacherPortal\TeacherPushService;
use App\Services\TeacherPortal\TeacherSyncService;
use App\Services\TeacherPortal\TeacherWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeacherPortalPhaseTwoController extends BaseApiController
{
    public function __construct(private TeacherWorkflowService $workflows, private TeacherHodScopeService $hod, private MarkEntryBatchService $batches, private MarkModerationService $moderation, private TeacherSyncService $sync, private TeacherPortalAttachmentService $attachments, private TeacherPushService $push, private TeacherDeviceService $devices) {}

    private function user()
    {
        return auth()->user();
    }

    public function tasks()
    {
        return $this->success(TeacherTaskResource::collection($this->workflows->tasks($this->user())));
    }

    public function submit(string $type, string $id)
    {
        return $this->success(new TeacherWorkflowResource($this->workflows->submit($this->user(), $type, $id)));
    }

    public function withdraw(string $type, string $id)
    {
        return $this->success(new TeacherWorkflowResource($this->workflows->withdraw($this->user(), $type, $id)));
    }

    public function history(string $type, string $id)
    {
        return $this->success(TeacherWorkflowHistoryResource::collection($this->workflows->history($this->user(), $type, $id)));
    }

    public function hodDashboard()
    {
        return $this->success(new TeacherPortalSafeResource(['review_queue_count' => $this->workflows->queue($this->user())->total(), 'scope' => $this->hod->scope($this->user())]));
    }

    public function reviewQueue(?string $workflow = null)
    {
        $items = $this->workflows->queue($this->user());
        if ($workflow) {
            $item = collect($items->items())->firstWhere('id', $workflow);
            abort_unless($item, 404);

            return $this->success(new TeacherWorkflowResource($item));
        }

        return $this->success(TeacherWorkflowResource::collection($items));
    }

    public function review(Request $request, string $workflow, string $action)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:4000']);

        return $this->success(new TeacherWorkflowResource($this->workflows->review($this->user(), $workflow, $action, $data['reason'] ?? null)));
    }

    public function hodTeachers()
    {
        $scope = $this->hod->scope($this->user());
        $query = DB::table('teachers')->where('school_id', $this->user()->school_id)->where('active', true)->where('is_deleted', false);
        if (! $scope['whole_school']) {
            $query->whereIn('id', $scope['teacher_ids']);
        }

        return $this->success(TeacherPortalSafeResource::collection($query->select('id', 'staff_no', 'designation')->paginate(30)));
    }

    public function compliance(bool $hod = false)
    {
        if ($hod) {
            $scope = $this->hod->scope($this->user());
        }
        $query = DB::table('teacher_workflows')->where('school_id', $this->user()->school_id)->where('entity_type', 'record_of_work');
        if (! $hod) {
            $teacher = app(TeacherPortalAccessService::class)->teacher($this->user());
            $query->where('teacher_id', $teacher->id);
        } elseif (! $scope['whole_school']) {
            $query->whereIn('teacher_assignment_id', DB::table('teacher_assignments')->where('school_id', $this->user()->school_id)->whereIn('learning_area_id', $scope['learning_area_ids'])->pluck('id'));
        }

        return $this->success(['total' => (clone $query)->count(), 'submitted' => (clone $query)->whereIn('state', ['submitted', 'under_review', 'approved'])->count(), 'approved' => (clone $query)->where('state', 'approved')->count(), 'changes_requested' => (clone $query)->where('state', 'changes_requested')->count()]);
    }

    public function hodCoverage()
    {
        $scope = $this->hod->scope($this->user());
        $query = DB::table('curriculum_coverage')->where('school_id', $this->user()->school_id)->where('is_deleted', false);
        if (! $scope['whole_school']) {
            $query->whereIn('teacher_assignment_id', DB::table('teacher_assignments')->where('school_id', $this->user()->school_id)->whereIn('learning_area_id', $scope['learning_area_ids'])->pluck('id'));
        }

        return $this->success(TeacherPortalSafeResource::collection($query->select('id', 'teacher_assignment_id', 'scheme_id', 'scheme_lesson_id', 'record_of_work_id', 'date_completed', 'strand', 'sub_strand', 'week_number', 'completed')->paginate(30)));
    }

    public function batches(?string $batch = null)
    {
        $query = $this->batches->query($this->user())->with('items');

        return $this->success($batch ? new MarkEntryBatchResource($query->findOrFail($batch)) : MarkEntryBatchResource::collection($query->latest()->paginate(30)));
    }

    public function saveMarks(Request $request, string $paper)
    {
        $data = $request->validate(['teacher_assignment_id' => 'nullable|uuid', 'marks' => ['required', 'array', 'min:1', 'max:100'], 'marks.*.learner_id' => 'required|uuid', 'marks.*.marks' => 'required|numeric|min:0']);

        return $this->success(new MarkEntryBatchResource($this->batches->save($this->user(), $paper, $data['marks'], $data['teacher_assignment_id'] ?? null)));
    }

    public function submitBatch(string $batch)
    {
        return $this->success(new MarkEntryBatchResource($this->batches->submit($this->user(), $batch)));
    }

    public function correction(Request $request, string $batch)
    {
        $data = $request->validate(['batch_item_id' => 'nullable|uuid', 'reason' => 'required|string|max:4000', 'proposed_marks' => 'nullable|numeric|min:0']);
        $id = $this->batches->correction($this->user(), $batch, $data);

        return $this->created(['id' => $id]);
    }

    public function moderation(?string $batch = null)
    {
        $query = $this->moderation->query($this->user())->with('items');

        return $this->success($batch ? new MarkEntryBatchResource($query->findOrFail($batch)) : MarkEntryBatchResource::collection($query->paginate(30)));
    }

    public function moderate(Request $request, string $batch, string $action)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:4000']);

        return $this->success(new MarkEntryBatchResource($this->moderation->decide($this->user(), $batch, $action, $data['reason'] ?? null)));
    }

    public function correctionRequests()
    {
        $query = DB::table('mark_correction_requests')->where('school_id', $this->user()->school_id);
        if (in_array($this->user()->role?->role_name, ['HOD', 'Principal', 'Deputy Principal', 'School Admin'], true)) {
            $query->whereIn('batch_id', $this->moderation->query($this->user())->select('id'));
        } else {
            $query->where('requested_by', $this->user()->id);
        }

        return $this->success(MarkCorrectionRequestResource::collection($query->select('id', 'batch_id', 'status', 'reason', 'proposed_marks', 'created_at')->paginate(30)));
    }

    public function decideCorrection(Request $request, string $correction, string $decision)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:4000']);

        return $this->success(new MarkCorrectionRequestResource($this->moderation->decideCorrection($this->user(), $correction, $decision, $data['reason'] ?? null)));
    }

    public function syncPush(Request $request)
    {
        $data = $request->validate(['device_id' => 'required|uuid', 'operations' => 'required|array|max:50', 'operations.*.operation_uuid' => 'required|uuid', 'operations.*.entity_type' => 'required|string|max:40', 'operations.*.entity_id' => 'required|uuid', 'operations.*.operation' => 'required|in:update', 'operations.*.base_version' => 'required|integer|min:1', 'operations.*.payload' => 'required|array']);

        return $this->success($this->sync->push($this->user(), $data['device_id'], $data['operations']));
    }

    public function syncPull(Request $request)
    {
        $data = $request->validate(['device_id' => 'required|uuid', 'cursor' => 'nullable|string|max:2000']);

        return $this->success($this->sync->pull($this->user(), $data['device_id'], $data['cursor'] ?? null));
    }

    public function syncStatus(Request $request)
    {
        $data = $request->validate(['device_id' => 'required|uuid']);

        return $this->success($this->sync->status($this->user(), $data['device_id']));
    }

    public function syncConflicts()
    {
        return $this->success(TeacherSyncConflictResource::collection($this->sync->conflicts($this->user())));
    }

    public function resolveConflict(string $conflict)
    {
        $this->sync->resolve($this->user(), $conflict);

        return $this->success(['resolved' => true]);
    }

    public function upload(Request $request)
    {
        $data = $request->validate(['context_type' => 'required|string|max:40', 'context_id' => 'nullable|uuid', 'file' => 'required|file|max:51200']);

        return $this->created(new TeacherAttachmentResource($this->attachments->upload($this->user(), $data['context_type'], $data['context_id'] ?? null, $data['file'])));
    }

    public function attachment(string $attachment)
    {
        return $this->success(new TeacherAttachmentResource($this->attachments->find($this->user(), $attachment)));
    }

    public function download(string $attachment)
    {
        return $this->attachments->download($this->user(), $attachment);
    }

    public function archiveAttachment(string $attachment)
    {
        $this->attachments->archive($this->user(), $attachment);

        return $this->success(null);
    }

    public function pushDeliveries()
    {
        return $this->success(TeacherPushDeliveryResource::collection($this->push->deliveries($this->user())));
    }

    public function rotatePushToken(Request $request, string $device)
    {
        $data = $request->validate(['push_token' => 'required|string|max:4096']);

        return $this->success(new TeacherPortalSafeResource($this->devices->rotatePushToken($this->user(), $device, $data['push_token'])));
    }

    public function removePushToken(string $device)
    {
        $this->devices->removePushToken($this->user(), $device);

        return $this->success(null);
    }
}
