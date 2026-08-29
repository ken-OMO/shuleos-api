<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Models\Learner;
use App\Models\LearnerModeOfStudyHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LearnerModeOfStudyController extends BaseCrudController
{
    private const MODULE = 'Learner Mode of Study';

    private const MODES = [
        'day_scholar',
        'boarder',
    ];

    public function show(Request $request, string $learner): JsonResponse
    {
        $schoolId = $this->schoolId($request);

        $learnerModel = $this->learner(
            $schoolId,
            $learner
        );

        return response()->json([
            'data' => [
                'learner_id' => $learnerModel->id,
                'mode_of_study' => $learnerModel->mode_of_study,
            ],
        ]);
    }

    public function update(Request $request, string $learner): JsonResponse
    {
        $schoolId = $this->schoolId($request);
        $userId = (string) $request->user()->id;

        $learnerModel = $this->learner(
            $schoolId,
            $learner
        );

        if (! $learnerModel->active) {
            throw ValidationException::withMessages([
                'learner' => [
                    'Only an active learner can have their mode of study changed.',
                ],
            ]);
        }

        $validated = $request->validate([
            'mode_of_study' => [
                'required',
                'string',
                Rule::in(self::MODES),
            ],
            'reason' => [
                'nullable',
                'string',
                'max:500',
            ],
        ]);

        $toMode = (string) $validated['mode_of_study'];

        if ($learnerModel->mode_of_study === $toMode) {
            throw ValidationException::withMessages([
                'mode_of_study' => [
                    'The learner already has the selected mode of study.',
                ],
            ]);
        }

        $result = DB::transaction(function () use (
            $request,
            $schoolId,
            $userId,
            $learnerModel,
            $toMode,
            $validated
        ) {
            /*
             * Lock the learner before deriving from_mode so concurrent
             * requests cannot record the same source state.
             */
            $lockedLearner = Learner::query()
                ->withoutGlobalScopes()
                ->where('id', $learnerModel->id)
                ->where('school_id', $schoolId)
                ->where('is_deleted', false)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedLearner->active) {
                throw ValidationException::withMessages([
                    'learner' => [
                        'Only an active learner can have their mode of study changed.',
                    ],
                ]);
            }

            if ($lockedLearner->mode_of_study === $toMode) {
                throw ValidationException::withMessages([
                    'mode_of_study' => [
                        'The learner already has the selected mode of study.',
                    ],
                ]);
            }

            $fromMode = $lockedLearner->mode_of_study;

            $oldValues = [
                'mode_of_study' => $fromMode,
            ];

            $now = now();

            $history = LearnerModeOfStudyHistory::query()
                ->create([
                    'id' => (string) Str::uuid(),
                    'school_id' => $schoolId,
                    'learner_id' => $lockedLearner->id,
                    'from_mode' => $fromMode,
                    'to_mode' => $toMode,
                    'reason' => $validated['reason'] ?? null,
                    'changed_by' => $userId,
                    'changed_at' => $now,
                    'created_at' => $now,
                ]);

            /*
             * mode_of_study intentionally remains outside Learner::$fillable.
             * This dedicated boundary owns mutation of the field.
             */
            $lockedLearner->mode_of_study = $toMode;
            $lockedLearner->save();
            $lockedLearner->refresh();

            $newValues = [
                'mode_of_study' => $lockedLearner->mode_of_study,
            ];

            $this->audit(
                $request,
                self::MODULE,
                'Change',
                $lockedLearner,
                $oldValues,
                $newValues,
                'Changed learner mode of study.'
            );

            return [
                'learner' => $lockedLearner,
                'history' => $history,
            ];
        });

        return response()->json([
            'message' => 'Learner mode of study updated successfully.',
            'data' => [
                'learner_id' => $result['learner']->id,
                'mode_of_study' => $result['learner']->mode_of_study,
                'change' => $this->historyResource(
                    $result['history']
                ),
            ],
        ]);
    }

    public function history(Request $request, string $learner): JsonResponse
    {
        $schoolId = $this->schoolId($request);

        $learnerModel = $this->learner(
            $schoolId,
            $learner
        );

        $history = LearnerModeOfStudyHistory::query()
            ->where('school_id', $schoolId)
            ->where('learner_id', $learnerModel->id)
            ->orderByDesc('changed_at')
            ->get()
            ->map(
                fn (LearnerModeOfStudyHistory $change): array => $this->historyResource($change)
            );

        return response()->json([
            'data' => $history,
        ]);
    }

    private function historyResource(
        LearnerModeOfStudyHistory $change
    ): array {
        return [
            'id' => $change->id,
            'learner_id' => $change->learner_id,
            'from_mode' => $change->from_mode,
            'to_mode' => $change->to_mode,
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

    private function schoolId(Request $request): string
    {
        $user = $request->user();

        abort_if(! $user, 401);
        abort_if(! $user->school_id, 403);

        $schoolId = (string) $user->school_id;

        /*
         * Fail closed if tenant middleware and authenticated authority
         * ever disagree.
         */
        $tenantSchoolId = $request->attributes->get(
            'tenant_school_id'
        );

        if (
            $tenantSchoolId !== null
            && (string) $tenantSchoolId !== $schoolId
        ) {
            abort(403);
        }

        return $schoolId;
    }
}
