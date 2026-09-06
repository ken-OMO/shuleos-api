<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Models\Learner;
use App\Models\LearnerLifecycleHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LearnerLifecycleController extends BaseCrudController
{
    private const MODULE = 'Learner Lifecycle';

    private const STATUSES = [
        'active',
        'withdrawn',
        'transferred',
        'graduated',
    ];

    public function show(
        Request $request,
        string $learner
    ): JsonResponse {
        $schoolId = $this->schoolId($request);

        $learnerModel = $this->learner(
            $schoolId,
            $learner
        );

        return response()->json([
            'data' => $this->learnerResource(
                $learnerModel
            ),
        ]);
    }

    public function update(
        Request $request,
        string $learner
    ): JsonResponse {
        $schoolId = $this->schoolId($request);
        $userId = (string) $request->user()->id;

        $this->learner(
            $schoolId,
            $learner
        );

        $validated = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in(self::STATUSES),
            ],
            'effective_date' => [
                'required',
                'date',
            ],
            'reason' => [
                'required',
                'string',
                'max:500',
            ],
        ]);

        $toStatus = (string) $validated['status'];

        $result = DB::transaction(function () use (
            $request,
            $schoolId,
            $userId,
            $learner,
            $toStatus,
            $validated
        ): array {
            /*
             * Lock before deriving from_status so concurrent lifecycle
             * mutations cannot record the same starting state.
             */
            $lockedLearner = Learner::query()
                ->withoutGlobalScopes()
                ->where('id', $learner)
                ->where('school_id', $schoolId)
                ->where('is_deleted', false)
                ->lockForUpdate()
                ->firstOrFail();

            $fromStatus = $lockedLearner
                ->lifecycle_status;

            /*
             * Legacy inactive learners may have NULL lifecycle_status.
             *
             * NULL -> active is allowed as corrective classification.
             * NULL -> terminal is also allowed when an administrator can
             * identify the correct historical state.
             */
            if ($fromStatus === $toStatus) {
                throw ValidationException::withMessages([
                    'status' => [
                        'The learner is already in the selected lifecycle status.',
                    ],
                ]);
            }

            if (
                $fromStatus !== null
                && $fromStatus !== 'active'
                && $toStatus !== 'active'
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        'A terminal learner lifecycle must be restored to active before another terminal status can be applied.',
                    ],
                ]);
            }

            $newActive = $toStatus === 'active';

            $oldValues = [
                'active' => (bool) $lockedLearner->active,
                'lifecycle_status' => $fromStatus,
            ];

            $now = now();

            $history = LearnerLifecycleHistory::query()
                ->create([
                    'id' => (string) Str::uuid(),
                    'school_id' => $schoolId,
                    'learner_id' => $lockedLearner->id,
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'effective_date' => $validated[
                        'effective_date'
                    ],
                    'reason' => $validated['reason'],
                    'changed_by' => $userId,
                    'changed_at' => $now,
                    'created_at' => $now,
                ]);

            /*
             * These fields deliberately bypass mass assignment.
             * This controller is the authoritative lifecycle mutation boundary.
             */
            $lockedLearner->active = $newActive;
            $lockedLearner->lifecycle_status =
                $toStatus;

            $lockedLearner->save();
            $lockedLearner->refresh();

            $newValues = [
                'active' => (bool) $lockedLearner->active,
                'lifecycle_status' => $lockedLearner->lifecycle_status,
            ];

            $this->audit(
                $request,
                self::MODULE,
                'Change',
                $lockedLearner,
                $oldValues,
                $newValues,
                'Changed learner lifecycle.'
            );

            return [
                'learner' => $lockedLearner,
                'history' => $history,
            ];
        });

        return response()->json([
            'message' => 'Learner lifecycle updated successfully.',
            'data' => [
                ...$this->learnerResource(
                    $result['learner']
                ),
                'change' => $this->historyResource(
                    $result['history']
                ),
            ],
        ]);
    }

    public function history(
        Request $request,
        string $learner
    ): JsonResponse {
        $schoolId = $this->schoolId($request);

        $learnerModel = $this->learner(
            $schoolId,
            $learner
        );

        $history = LearnerLifecycleHistory::query()
            ->where('school_id', $schoolId)
            ->where(
                'learner_id',
                $learnerModel->id
            )
            ->orderByDesc('changed_at')
            ->get()
            ->map(
                fn (
                    LearnerLifecycleHistory $change
                ): array => $this->historyResource(
                    $change
                )
            );

        return response()->json([
            'data' => $history,
        ]);
    }

    private function learnerResource(
        Learner $learner
    ): array {
        return [
            'learner_id' => $learner->id,
            'active' => (bool) $learner->active,
            'lifecycle_status' => $learner->lifecycle_status,
        ];
    }

    private function historyResource(
        LearnerLifecycleHistory $change
    ): array {
        return [
            'id' => $change->id,
            'learner_id' => $change->learner_id,
            'from_status' => $change->from_status,
            'to_status' => $change->to_status,
            'effective_date' => $change->effective_date,
            'reason' => $change->reason,
            'changed_at' => $change->changed_at,
        ];
    }

    private function learner(
        string $schoolId,
        string $learner
    ): Learner {
        return Learner::query()
            ->withoutGlobalScopes()
            ->where('id', $learner)
            ->where('school_id', $schoolId)
            ->where('is_deleted', false)
            ->firstOrFail();
    }

    private function schoolId(
        Request $request
    ): string {
        $user = $request->user();

        abort_if(! $user, 401);
        abort_if(! $user->school_id, 403);

        $schoolId = (string) $user->school_id;

        /*
         * Fail closed if authenticated school authority and tenant
         * middleware ever disagree.
         */
        $tenantSchoolId = $request->attributes->get(
            'tenant_school_id'
        );

        if (
            $tenantSchoolId === null
            || (string) $tenantSchoolId !== $schoolId
        ) {
            abort(403);
        }

        return $schoolId;
    }
}
