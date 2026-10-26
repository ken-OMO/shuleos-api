<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Models\User;
use App\Services\Auth\AuthContextService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssessmentReissueRequestService
{
    public function __construct(
        private readonly ExamResultLifecycleService $lifecycle,
        private readonly AssessmentCorrectionProposalService $proposals,
        private readonly AssessmentPublicationArchiveService $archive,
        private readonly AuthContextService $access
    ) {}

    public function submit(User $actor, string $examId, string $reason, array $changes): string
    {
        $this->authorize($actor, 'request_assessment_reissues');
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($actor, $examId, $reason, $changes): string {
            $schoolId = (string) $actor->school_id;
            $this->lifecycle->lockExamForWrite($schoolId, $examId);

            if (DB::table('assessment_reissue_requests')
                ->where('school_id', $schoolId)->where('exam_id', $examId)
                ->whereIn('status', ['pending', 'approved', 'prepared', 'ready'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'reissue_request' => 'An active correction request already exists for this exam.',
                ]);
            }

            $published = false;
            foreach (['report_cards', 'merit_lists'] as $table) {
                $published = DB::table($table)
                    ->where('school_id', $schoolId)->where('exam_id', $examId)
                    ->where('status', 'published')->exists() || $published;
            }

            if (! $published) {
                throw ValidationException::withMessages([
                    'exam_id' => 'This workflow requires an existing published output.',
                ]);
            }

            $proposal = $this->proposals->normalize($schoolId, $examId, $changes);
            $this->archive->preserveOriginals($schoolId, $examId);

            $id = (string) Str::uuid();
            $proposalHash = $this->archive->fingerprint($proposal);
            $sourceHash = $this->sourceFingerprint($schoolId, $examId);

            DB::table('assessment_reissue_requests')->insert([
                'id' => $id,
                'school_id' => $schoolId,
                'exam_id' => $examId,
                'reason' => $reason,
                'proposed_changes' => json_encode($proposal, JSON_THROW_ON_ERROR),
                'proposal_sha256' => $proposalHash,
                'source_sha256' => $sourceHash,
                'status' => 'pending',
                'requested_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->event($id, $actor, 'submitted', $reason, [
                'proposal_sha256' => $proposalHash,
                'source_sha256' => $sourceHash,
                'change_count' => count($proposal),
            ]);

            return $id;
        });
    }

    public function decide(
        User $actor,
        string $requestId,
        string $decision,
        string $reason
    ): void {
        $this->authorize($actor, 'approve_assessment_reissues');
        $reason = $this->reason($reason);
        Validator::make(['decision' => $decision], [
            'decision' => 'required|in:approved,rejected',
        ])->validate();

        DB::transaction(function () use ($actor, $requestId, $decision, $reason): void {
            $schoolId = (string) $actor->school_id;
            $candidate = DB::table('assessment_reissue_requests')
                ->where('school_id', $schoolId)->where('id', $requestId)
                ->first();

            if (! $candidate) {
                throw ValidationException::withMessages([
                    'reissue_request' => 'Correction request not found for this school.',
                ]);
            }

            $this->lifecycle->lockExamForWrite($schoolId, $candidate->exam_id);
            $request = DB::table('assessment_reissue_requests')
                ->where('school_id', $schoolId)->where('id', $requestId)
                ->lockForUpdate()->firstOrFail();

            if ($request->status !== 'pending') {
                throw ValidationException::withMessages([
                    'reissue_request' => 'Only a pending request can be decided.',
                ]);
            }

            if ($request->requested_by === $actor->id) {
                throw new AuthorizationException(
                    'A correction request must be decided by another authorized user.'
                );
            }

            $proposal = json_decode(
                $request->proposed_changes, true, 512, JSON_THROW_ON_ERROR
            );

            if (! hash_equals(
                $request->proposal_sha256,
                $this->archive->fingerprint($proposal)
            )) {
                throw ValidationException::withMessages([
                    'reissue_request' => 'The stored correction proposal has changed.',
                ]);
            }

            if ($decision === 'approved') {
                if (! hash_equals(
                    $request->source_sha256,
                    $this->sourceFingerprint($schoolId, $request->exam_id)
                )) {
                    throw ValidationException::withMessages([
                        'reissue_request' => 'Exam source data changed. Reject this request and submit a fresh proposal.',
                    ]);
                }

                $changes = array_map(fn (array $item): array => [
                    'exam_result_id' => $item['exam_result_id'],
                    'marks' => $item['after']['marks'],
                    'is_absent' => $item['after']['is_absent'],
                ], $proposal);

                $validated = $this->proposals->normalize(
                    $schoolId, $request->exam_id, $changes
                );

                if (! hash_equals(
                    $request->proposal_sha256,
                    $this->archive->fingerprint($validated)
                )) {
                    throw ValidationException::withMessages([
                        'reissue_request' => 'The proposed correction no longer matches its source results.',
                    ]);
                }
            }

            DB::table('assessment_reissue_requests')->where('id', $requestId)
                ->update([
                    'status' => $decision,
                    'decided_by' => $actor->id,
                    'decision_reason' => $reason,
                    'decided_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->event($requestId, $actor, $decision, $reason, [
                'proposal_sha256' => $request->proposal_sha256,
                'source_sha256' => $request->source_sha256,
            ]);
        });
    }

    public function cancel(User $actor, string $requestId, string $reason): void
    {
        $this->authorize($actor, 'approve_assessment_reissues');
        $reason = $this->reason($reason);

        DB::transaction(function () use ($actor, $requestId, $reason): void {
            $schoolId = (string) $actor->school_id;
            $candidate = DB::table('assessment_reissue_requests')
                ->where('school_id', $schoolId)->where('id', $requestId)->first();

            if (! $candidate) {
                throw ValidationException::withMessages([
                    'reissue_request' => 'Correction request not found for this school.',
                ]);
            }

            $this->lifecycle->lockExamForWrite($schoolId, $candidate->exam_id);
            $request = DB::table('assessment_reissue_requests')
                ->where('school_id', $schoolId)->where('id', $requestId)
                ->lockForUpdate()->firstOrFail();

            if (! in_array($request->status, ['pending', 'approved', 'prepared', 'ready'], true)) {
                throw ValidationException::withMessages([
                    'reissue_request' => 'Only an active, unpublished correction request can be cancelled.',
                ]);
            }

            DB::table('assessment_reissue_requests')->where('id', $requestId)->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);

            $this->event($requestId, $actor, 'cancelled', $reason, [
                'previous_status' => $request->status,
                'prepared_sha256' => $request->prepared_sha256,
            ]);
        });
    }

    private function authorize(User $actor, string $permission): void
    {
        if (! $actor->school_id || ! $this->access->hasPermission($actor, $permission)) {
            throw new AuthorizationException(
                'You are not authorized for this assessment correction action.'
            );
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        Validator::make(['reason' => $reason], [
            'reason' => 'required|string|max:5000',
        ])->validate();

        return $reason;
    }

    public function sourceFingerprint(string $schoolId, string $examId): string
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Source fingerprints require a transaction.');
        }

        $this->lifecycle->lockExamForWrite($schoolId, $examId);
        $payload = [];

        foreach ([
            'exams', 'exam_learning_areas', 'exam_grading_snapshots',
            'exam_rosters', 'exam_roster_learners',
        ] as $table) {
            $query = DB::table($table);

            if ($table === 'exams') {
                $query->where('id', $examId)->where('school_id', $schoolId);
            } else {
                $query->where('exam_id', $examId);
                if ($table !== 'exam_learning_areas') {
                    $query->where('school_id', $schoolId);
                }
            }

            $payload[$table] = $query->orderBy('id')->get()
                ->map(fn ($row): array => (array) $row)->all();
        }

        // Grading resolves education levels through the roster's grade definitions.
        // Retain these locks until approval/preparation/review/publication commits.
        $participants = collect($payload['exam_roster_learners']);
        $payload['membership_grades'] = DB::table('grades')
            ->where('school_id', $schoolId)
            ->whereIn('id', $participants->pluck('grade_id')->unique())
            ->orderBy('id')->sharedLock()
            ->get(['id', 'school_id', 'education_level_id', 'active'])
            ->map(fn ($row): array => (array) $row)->all();

        $payload['membership_streams'] = DB::table('streams')
            ->where('school_id', $schoolId)
            ->whereIn('id', $participants->pluck('stream_id')->filter()->unique())
            ->orderBy('id')->sharedLock()
            ->get(['id', 'school_id', 'grade_id', 'active'])
            ->map(fn ($row): array => (array) $row)->all();

        $payload['exam_papers'] = DB::table('exam_papers')
            ->whereIn('exam_learning_area_id', function ($query) use ($examId): void {
                $query->select('id')->from('exam_learning_areas')
                    ->where('exam_id', $examId);
            })->orderBy('id')->get()
            ->map(fn ($row): array => (array) $row)->all();

        foreach (['exam_results', 'learning_area_results', 'merit_lists', 'report_cards'] as $table) {
            $query = DB::table($table)->where('exam_id', $examId);

            if ($table !== 'exam_results') {
                $query->where('school_id', $schoolId);
            }

            $payload[$table] = $query->orderBy('id')
                ->get()->map(fn ($row): array => (array) $row)->all();
        }

        $payload['report_card_learning_areas'] = DB::table('report_card_learning_areas')
            ->whereIn('report_card_id', function ($query) use ($schoolId, $examId): void {
                $query->select('id')->from('report_cards')
                    ->where('school_id', $schoolId)->where('exam_id', $examId);
            })->orderBy('id')->get()
            ->map(fn ($row): array => (array) $row)->all();

        return $this->archive->fingerprint($payload);
    }

    private function event(
        string $requestId,
        User $actor,
        string $action,
        string $reason,
        array $details
    ): void {
        DB::table('assessment_reissue_events')->insert([
            'id' => (string) Str::uuid(),
            'request_id' => $requestId,
            'school_id' => $actor->school_id,
            'actor_id' => $actor->id,
            'action' => $action,
            'reason' => $reason,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
