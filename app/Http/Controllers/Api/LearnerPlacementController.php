<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Models\Grade;
use App\Models\Learner;
use App\Models\LearnerPlacementHistory;
use App\Models\Stream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LearnerPlacementController extends BaseCrudController
{
    private const MODULE = 'Learner Placement';

    public function index(Request $request, string $learner): JsonResponse
    {
        $schoolId = $this->schoolId();
        $learnerModel = $this->learner($schoolId, $learner);

        $history = LearnerPlacementHistory::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('learner_id', $learnerModel->id)
            ->orderByDesc('placed_at')
            ->get()
            ->map(
                fn (LearnerPlacementHistory $placement) => $this->resource($placement)
            );

        return response()->json([
            'data' => $history,
        ]);
    }

    public function store(Request $request, string $learner): JsonResponse
    {
        $schoolId = $this->schoolId();
        $userId = (string) auth()->id();

        $learnerModel = $this->learner(
            $schoolId,
            $learner
        );

        if (! $learnerModel->active) {
            throw ValidationException::withMessages([
                'learner' => [
                    'Only an active learner can be placed.',
                ],
            ]);
        }

        $validated = $request->validate([
            'grade_id' => [
                'required',
                'uuid',
            ],
            'stream_id' => [
                'required',
                'uuid',
            ],
            'reason' => [
                'nullable',
                'string',
                'max:500',
            ],
        ]);

        $grade = Grade::query()
            ->withoutGlobalScopes()
            ->where(
                'id',
                $validated['grade_id']
            )
            ->where('school_id', $schoolId)
            ->where('active', true)
            ->first();

        if (! $grade) {
            throw ValidationException::withMessages([
                'grade_id' => [
                    'The selected grade is invalid.',
                ],
            ]);
        }

        $stream = Stream::query()
            ->withoutGlobalScopes()
            ->where(
                'id',
                $validated['stream_id']
            )
            ->where('school_id', $schoolId)
            ->where('grade_id', $grade->id)
            ->where('active', true)
            ->first();

        if (! $stream) {
            throw ValidationException::withMessages([
                'stream_id' => [
                    'The selected stream is invalid for the selected grade.',
                ],
            ]);
        }

        if (
            $learnerModel->grade_id === $grade->id
            && $learnerModel->stream_id === $stream->id
        ) {
            throw ValidationException::withMessages([
                'placement' => [
                    'The learner is already in the selected grade and stream.',
                ],
            ]);
        }

        $result = DB::transaction(function () use (
            $request,
            $schoolId,
            $userId,
            $learnerModel,
            $grade,
            $stream,
            $validated
        ) {
            /*
             * The learner row is locked before the historical
             * source placement is derived. This prevents two
             * concurrent placement requests from recording the
             * same starting position.
             */
            $lockedLearner = Learner::query()
                ->withoutGlobalScopes()
                ->where(
                    'id',
                    $learnerModel->id
                )
                ->where('school_id', $schoolId)
                ->where('is_deleted', false)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedLearner->active) {
                throw ValidationException::withMessages([
                    'learner' => [
                        'Only an active learner can be placed.',
                    ],
                ]);
            }

            if (
                $lockedLearner->grade_id === $grade->id
                && $lockedLearner->stream_id === $stream->id
            ) {
                throw ValidationException::withMessages([
                    'placement' => [
                        'The learner is already in the selected grade and stream.',
                    ],
                ]);
            }

            $placementType =
                $lockedLearner->grade_id === $grade->id
                    ? 'stream_change'
                    : 'grade_change';

            $oldValues = [
                'grade_id' => $lockedLearner->grade_id,
                'stream_id' => $lockedLearner->stream_id,
            ];

            $now = now();

            $history = LearnerPlacementHistory::query()
                ->withoutGlobalScopes()
                ->create([
                    'id' => (string) Str::uuid(),
                    'school_id' => $schoolId,
                    'learner_id' => $lockedLearner->id,
                    'from_grade_id' => $lockedLearner->grade_id,
                    'from_stream_id' => $lockedLearner->stream_id,
                    'to_grade_id' => $grade->id,
                    'to_stream_id' => $stream->id,
                    'placement_type' => $placementType,
                    'reason' => $validated['reason'] ?? null,
                    'placed_by' => $userId,
                    'placed_at' => $now,
                    'created_at' => $now,
                ]);

            $lockedLearner->grade_id = $grade->id;
            $lockedLearner->stream_id = $stream->id;
            $lockedLearner->save();
            $lockedLearner->refresh();

            $newValues = [
                'grade_id' => $lockedLearner->grade_id,
                'stream_id' => $lockedLearner->stream_id,
            ];

            $this->audit(
                $request,
                self::MODULE,
                'Place',
                $lockedLearner,
                $oldValues,
                $newValues,
                'Changed learner grade/stream placement.'
            );

            return [
                'learner' => $lockedLearner,
                'history' => $history,
            ];
        });

        return response()->json([
            'message' => 'Learner placement updated successfully.',
            'data' => [
                'learner_id' => $result['learner']->id,
                'grade_id' => $result['learner']->grade_id,
                'stream_id' => $result['learner']->stream_id,
                'placement' => $this->resource(
                    $result['history']
                ),
            ],
        ]);
    }

    private function resource(
        LearnerPlacementHistory $placement
    ): array {
        return [
            'id' => $placement->id,
            'learner_id' => $placement->learner_id,
            'from_grade_id' => $placement->from_grade_id,
            'from_stream_id' => $placement->from_stream_id,
            'to_grade_id' => $placement->to_grade_id,
            'to_stream_id' => $placement->to_stream_id,
            'placement_type' => $placement->placement_type,
            'reason' => $placement->reason,
            'placed_at' => $placement->placed_at,
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

    private function schoolId(): string
    {
        $schoolId = auth()->user()?->school_id;

        abort_if(! $schoolId, 403);

        return (string) $schoolId;
    }
}
