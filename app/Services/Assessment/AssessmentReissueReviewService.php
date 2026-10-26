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

class AssessmentReissueReviewService
{
    public function approve(
        User $actor,
        string $requestId,
        string $expectedFingerprint,
        string $reason
    ): void {
        if (! $actor->school_id || ! app(AuthContextService::class)
            ->hasPermission($actor, 'review_assessment_reissues')) {
            throw new AuthorizationException('You cannot approve prepared assessment revisions.');
        }

        $reason = trim($reason);
        Validator::make([
            'reason' => $reason,
            'prepared_sha256' => $expectedFingerprint,
        ], [
            'reason' => 'required|string|max:5000',
            'prepared_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
        ])->validate();

        DB::transaction(function () use ($actor, $requestId, $expectedFingerprint, $reason): void {
            $schoolId = (string) $actor->school_id;
            $candidate = DB::table('assessment_reissue_requests')
                ->where('school_id', $schoolId)->where('id', $requestId)->first();

            if (! $candidate) {
                throw ValidationException::withMessages([
                    'reissue_request' => 'Correction request not found for this school.',
                ]);
            }

            app(ExamResultLifecycleService::class)
                ->lockExamForWrite($schoolId, $candidate->exam_id);
            $request = DB::table('assessment_reissue_requests')
                ->where('school_id', $schoolId)->where('id', $requestId)
                ->lockForUpdate()->firstOrFail();

            if ($request->status !== 'prepared' || ! $request->prepared_sha256) {
                throw ValidationException::withMessages([
                    'reissue_request' => 'Only a prepared request can be reviewed.',
                ]);
            }

            if ($request->requested_by === $actor->id
                || DB::table('assessment_reissue_drafts')
                    ->where('request_id', $requestId)
                    ->where('prepared_by', $actor->id)->exists()) {
                throw new AuthorizationException(
                    'Prepared revisions require a reviewer other than the requester and preparer.'
                );
            }

            $archive = app(AssessmentPublicationArchiveService::class);
            $proposal = json_decode(
                $request->proposed_changes, true, 512, JSON_THROW_ON_ERROR
            );
            $actual = app(AssessmentReissuePreparationService::class)
                ->draftFingerprint($schoolId, $requestId);

            if (! hash_equals($request->prepared_sha256, $expectedFingerprint)
                || ! hash_equals($request->prepared_sha256, $actual)
                || ! hash_equals($request->proposal_sha256, $archive->fingerprint($proposal))
                || ! hash_equals(
                    $request->source_sha256,
                    app(AssessmentReissueRequestService::class)
                        ->sourceFingerprint($schoolId, $request->exam_id)
                )) {
                throw ValidationException::withMessages([
                    'reissue_request' => 'The proposal, source or prepared revisions changed. Review approval was not recorded.',
                ]);
            }

            DB::table('assessment_reissue_requests')->where('id', $requestId)->update([
                'status' => 'ready',
                'reviewed_by' => $actor->id,
                'review_reason' => $reason,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('assessment_reissue_events')->insert([
                'id' => (string) Str::uuid(),
                'request_id' => $requestId,
                'school_id' => $schoolId,
                'actor_id' => $actor->id,
                'action' => 'review_approved',
                'reason' => $reason,
                'details' => json_encode([
                    'prepared_sha256' => $actual,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });
    }
}
