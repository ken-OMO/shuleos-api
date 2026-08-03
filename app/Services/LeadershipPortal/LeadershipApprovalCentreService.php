<?php

namespace App\Services\LeadershipPortal;

use App\Models\User;
use App\Services\Communication\CommunicationService;
use App\Services\Finance\FinanceRefundService;
use App\Services\TeacherPortal\MarkModerationService;
use App\Services\TeacherPortal\TeacherWorkflowService;
use App\Services\Timetable\TimetablePublicationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadershipApprovalCentreService
{
    public function __construct(
        private LeadershipPortalAccessService $access,
        private TeacherWorkflowService $workflows,
        private MarkModerationService $marks,
        private CommunicationService $communications,
        private FinanceRefundService $refunds,
        private TimetablePublicationService $timetables,
        private LeadershipPortalAuditService $audit,
    ) {}

    public function index(User $user): array
    {
        $scope = $this->scope($user);
        $items = collect();
        $workflowQuery = DB::table('teacher_workflows')->where('school_id', $scope['school_id'])->whereIn('state', ['submitted', 'under_review']);
        if ($scope['role_key'] === 'hod') {
            $workflowQuery->whereIn('teacher_id', $scope['teacher_ids']);
        }
        $workflowQuery->latest('submitted_at')->limit(50)->get()->each(fn ($row) => $items->push($this->item('teacher_workflow', $row->id, $row->entity_type, $row->state, $row->submitted_by, $row->submitted_at)));

        $markQuery = DB::table('mark_entry_batches')->where('school_id', $scope['school_id'])->where('status', 'submitted');
        if ($scope['role_key'] === 'hod') {
            $markQuery->whereIn('teacher_id', $scope['teacher_ids']);
        }
        $markQuery->latest('submitted_at')->limit(50)->get()->each(fn ($row) => $items->push($this->item('mark_entry_batch', $row->id, 'mark_entry_batch', $row->status, $row->entered_by, $row->submitted_at)));

        if ($this->access->has($user, 'approve_communications')) {
            DB::table('communications')->where('school_id', $scope['school_id'])->where('status', 'pending_approval')->latest()->limit(50)->get()->each(fn ($row) => $items->push($this->item('communication', $row->id, $row->communication_type, $row->status, $row->sender_user_id, $row->created_at)));
        }
        if ($this->access->has($user, 'approve_fee_refunds')) {
            DB::table('fee_refunds')->where('school_id', $scope['school_id'])->whereIn('status', ['requested', 'under_review'])->latest('requested_at')->limit(50)->get()->each(fn ($row) => $items->push($this->item('fee_refund', $row->id, 'fee_refund', $row->status, $row->requested_by, $row->requested_at)));
        }
        if ($this->access->has($user, 'manage_timetable')) {
            DB::table('timetables')->where('school_id', $scope['school_id'])->where('status', 'valid')->latest()->limit(50)->get()->each(fn ($row) => $items->push($this->item('timetable', $row->id, 'timetable_publication', $row->status, $row->created_by, $row->created_at)));
        }

        $page = max(1, request()->integer('page', 1));
        $perPage = min(50, max(1, request()->integer('per_page', 20)));
        $sorted = $items->unique('id')->sortByDesc('submitted_at')->values();

        return ['items' => $sorted->forPage($page, $perPage)->values(), 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $sorted->count()]];
    }

    public function summary(User $user): array
    {
        $scope = $this->scope($user);
        $workflow = DB::table('teacher_workflows')->where('school_id', $scope['school_id'])->whereIn('state', ['submitted', 'under_review']);
        $marks = DB::table('mark_entry_batches')->where('school_id', $scope['school_id'])->where('status', 'submitted');
        if ($scope['role_key'] === 'hod') {
            $workflow->whereIn('teacher_id', $scope['teacher_ids']);
            $marks->whereIn('teacher_id', $scope['teacher_ids']);
        }
        $counts = [
            'teacher_workflow' => $workflow->count(),
            'mark_entry_batch' => $marks->count(),
            'communication' => $this->access->has($user, 'approve_communications') ? DB::table('communications')->where('school_id', $scope['school_id'])->where('status', 'pending_approval')->count() : 0,
            'fee_refund' => $this->access->has($user, 'approve_fee_refunds') ? DB::table('fee_refunds')->where('school_id', $scope['school_id'])->whereIn('status', ['requested', 'under_review'])->count() : 0,
            'timetable' => $this->access->has($user, 'manage_timetable') ? DB::table('timetables')->where('school_id', $scope['school_id'])->where('status', 'valid')->count() : 0,
        ];

        return ['total' => array_sum($counts), 'by_category' => $counts];
    }

    public function show(User $user, string $reference): array
    {
        [$type, $id] = $this->parse($reference);
        $scope = $this->scope($user);
        $this->assertCategoryPermission($user, $type);
        $definition = match ($type) {
            'teacher_workflow' => ['teacher_workflows', 'state', 'submitted_by', 'submitted_at', 'entity_type'],
            'mark_entry_batch' => ['mark_entry_batches', 'status', 'entered_by', 'submitted_at', null],
            'communication' => ['communications', 'status', 'sender_user_id', 'created_at', 'communication_type'],
            'fee_refund' => ['fee_refunds', 'status', 'requested_by', 'requested_at', null],
            'timetable' => ['timetables', 'status', 'created_by', 'created_at', null],
        };
        [$table, $status, $submitter, $date, $entityType] = $definition;
        $query = DB::table($table)->where('school_id', $scope['school_id'])->where('id', $id);
        if ($scope['role_key'] === 'hod' && in_array($type, ['teacher_workflow', 'mark_entry_batch'], true)) {
            $query->whereIn('teacher_id', $scope['teacher_ids']);
        }
        $row = $query->first();
        abort_unless($row, 404);
        $item = $this->item($type, $id, $entityType ? $row->{$entityType} : $type, $row->{$status}, $row->{$submitter}, $row->{$date});

        return $item;
    }

    public function decide(User $user, string $reference, string $action, ?string $reason): mixed
    {
        $this->scope($user);
        if (in_array($action, ['changes_requested', 'rejected'], true) && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }
        [$type, $id] = $this->parse($reference);
        $this->assertCategoryPermission($user, $type);
        $result = match ($type) {
            'teacher_workflow' => $this->workflows->review($user, $id, $action === 'approved' ? 'approved' : $action, $reason),
            'mark_entry_batch' => $this->marks->decide($user, $id, $action === 'approved' ? 'approved' : $action, $reason),
            'communication' => $action === 'approved'
                ? $this->communications->approve($user, $id)
                : $this->communications->reject($user, $id, (string) $reason),
            'fee_refund' => $this->financeDecision($user, $id, $action, $reason),
            'timetable' => $this->timetableDecision($user, $id, $action),
            default => throw ValidationException::withMessages(['approval' => 'Unsupported approval category.']),
        };
        $this->audit->record($user, 'approval_'.$action, $type, $id, ['reason' => $reason]);

        return $result;
    }

    private function financeDecision(User $user, string $id, string $action, ?string $reason): mixed
    {
        $this->access->require($user, 'approve_fee_refunds');
        if ($action === 'changes_requested') {
            throw ValidationException::withMessages(['approval' => 'Fee refunds support approve or reject only.']);
        }

        return $this->refunds->decide($user, $id, $action === 'approved' ? 'approved' : 'rejected', $reason);
    }

    private function timetableDecision(User $user, string $id, string $action): mixed
    {
        $this->access->require($user, 'manage_timetable');
        if ($action !== 'approved') {
            throw ValidationException::withMessages(['approval' => 'Timetable rejection requires the timetable revision workflow.']);
        }

        return $this->timetables->approve($user, $id);
    }

    private function scope(User $user): array
    {
        $this->access->require($user, 'review_cross_module_approvals');

        return $this->access->scope($user);
    }

    private function assertCategoryPermission(User $user, string $type): void
    {
        $permission = match ($type) {
            'communication' => 'approve_communications',
            'fee_refund' => 'approve_fee_refunds',
            'timetable' => 'manage_timetable',
            default => null,
        };
        if ($permission && ! $this->access->has($user, $permission)) {
            throw new AuthorizationException('Approval category permission denied.');
        }
    }

    private function item(string $category, string $id, string $type, string $status, ?string $submitter, mixed $submittedAt): array
    {
        return [
            'id' => $category.':'.$id,
            'category' => $category,
            'type' => $type,
            'status' => $status,
            'submitted_by_current_user' => $submitter === auth()->id(),
            'submitted_at' => $submittedAt,
        ];
    }

    private function parse(string $reference): array
    {
        $parts = explode(':', $reference, 2);
        if (count($parts) !== 2 || ! in_array($parts[0], ['teacher_workflow', 'mark_entry_batch', 'communication', 'fee_refund', 'timetable'], true)) {
            throw ValidationException::withMessages(['approval' => 'Invalid approval reference.']);
        }

        return $parts;
    }
}
