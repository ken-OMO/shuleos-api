<?php

namespace App\Services\Assessment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExamRosterService
{
    public function __construct(private readonly ExamResultLifecycleService $lifecycle) {}

    /**
     * Replace the complete roster. Version zero means no roster exists yet.
     * Each selection contains learner_id and exam_learning_area_ids.
     */
    public function replace(
        string $schoolId,
        string $examId,
        string $userId,
        int $expectedVersion,
        array $selections
    ): array {
        $validated = Validator::make([
            'version' => $expectedVersion,
            'learners' => $selections,
        ], [
            'version' => 'required|integer|min:0',
            'learners' => 'required|array|min:1|max:2000',
            'learners.*' => 'required|array:learner_id,exam_learning_area_ids',
            'learners.*.learner_id' => 'required|uuid|distinct',
            'learners.*.exam_learning_area_ids' => 'required|array|min:1|max:50',
            'learners.*.exam_learning_area_ids.*' => 'required|uuid',
        ])->validate();

        return DB::transaction(function () use ($schoolId, $examId, $userId, $expectedVersion, $validated) {
            $exam = $this->lifecycle->lockExamForWrite($schoolId, $examId);
            $this->lifecycle->assertMutable($exam);

            $actor = DB::table('users')->where('id', $userId)
                ->where('school_id', $schoolId)->where('active', true)
                ->where('is_deleted', false)->exists();

            if (! $actor) {
                throw ValidationException::withMessages([
                    'user_id' => 'An active user from this school is required.',
                ]);
            }

            $roster = DB::table('exam_rosters')
                ->where('school_id', $schoolId)->where('exam_id', $examId)
                ->lockForUpdate()->first();

            // Existing published exams may establish their first explicit roster.
            // Replacement remains draft-only; assertMutable also protects published outputs.
            if ($exam->status !== 'draft'
                && ! ($exam->status === 'published' && ! $roster && $expectedVersion === 0)) {
                throw ValidationException::withMessages([
                    'exam_id' => 'Only an initial roster may be established after an exam is published.',
                ]);
            }

            if (($roster ? (int) $roster->version : 0) !== $expectedVersion) {
                throw ValidationException::withMessages([
                    'version' => 'The roster changed. Reload it before saving.',
                ]);
            }

            $previous = $roster
                ? DB::table('exam_roster_learners')->where('roster_id', $roster->id)
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('learner_id')
                : collect();

            $learnerIds = collect($validated['learners'])->pluck('learner_id');
            $learners = DB::table('learners')->where('school_id', $schoolId)
                ->whereIn('id', $learnerIds)->orderBy('id')
                ->lockForUpdate()->get()->keyBy('id');

            if ($learners->count() !== $learnerIds->count()) {
                throw ValidationException::withMessages([
                    'learners' => 'Every learner must belong to this school.',
                ]);
            }

            $areaIds = collect($validated['learners'])
                ->flatMap(fn ($row) => $row['exam_learning_area_ids'])->unique();

            $areas = DB::table('exam_learning_areas')->where('exam_id', $examId)
                ->whereIn('id', $areaIds)->where('is_deleted', false)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if ($areas->count() !== $areaIds->count()) {
                throw ValidationException::withMessages([
                    'learning_areas' => 'Every selected learning area must belong to this exam.',
                ]);
            }

            $papers = DB::table('exam_papers')
                ->whereIn('exam_learning_area_id', $areaIds)
                ->where('is_deleted', false)->orderBy('id')
                ->lockForUpdate()->get()->groupBy('exam_learning_area_id');

            foreach ($areas as $area) {
                $configured = $papers->get($area->id, collect());
                $numbers = $configured->pluck('paper_number')->map(fn ($n) => (int) $n);
                $maximum = (float) $configured->sum('max_marks');

                if ((int) $area->number_of_papers < 1
                    || $configured->count() !== (int) $area->number_of_papers
                    || $numbers->unique()->count() !== $configured->count()
                    || $numbers->contains(fn ($n) => $n < 1)
                    || $configured->contains(fn ($paper) => (float) $paper->max_marks <= 0)
                    || $maximum <= 0
                    || abs($maximum - (float) $area->total_marks) > 0.001) {
                    throw ValidationException::withMessages([
                        'papers' => 'Selected learning areas require complete, valid paper configuration.',
                    ]);
                }
            }

            foreach ($learners as $learner) {
                if ($previous->has($learner->id)) {
                    continue;
                }

                if (! $learner->active || $learner->is_deleted) {
                    throw ValidationException::withMessages([
                        'learners' => 'New exam participants must be active and unarchived.',
                    ]);
                }

                $gradeValid = DB::table('grades')->where('id', $learner->grade_id)
                    ->where('school_id', $schoolId)->exists();
                $streamValid = ! $learner->stream_id || DB::table('streams')
                    ->where('id', $learner->stream_id)->where('school_id', $schoolId)
                    ->where('grade_id', $learner->grade_id)->exists();

                if (! $gradeValid || ! $streamValid) {
                    throw ValidationException::withMessages([
                        'learners' => 'Learner grade and stream membership must belong to this school and match.',
                    ]);
                }
            }

            $paperExamIdentity = DB::connection()->getSchemaBuilder()
                ->hasColumn('exam_roster_papers', 'exam_id');

            $now = now();
            $rosterId = $roster->id ?? (string) Str::uuid();
            $version = $expectedVersion + 1;

            if ($roster) {
                DB::table('exam_rosters')->where('id', $rosterId)->update([
                    'version' => $version, 'updated_by' => $userId, 'updated_at' => $now,
                ]);
            } else {
                DB::table('exam_rosters')->insert([
                    'id' => $rosterId, 'school_id' => $schoolId, 'exam_id' => $examId,
                    'version' => $version, 'created_by' => $userId, 'updated_by' => $userId,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            // Cascading deletion removes the previous expected-paper selections.
            DB::table('exam_roster_learners')->where('roster_id', $rosterId)->delete();

            foreach (array_chunk($validated['learners'], 250) as $batch) {
                $participants = [];
                $participantIds = [];

                foreach ($batch as $selection) {
                    $learner = $learners[$selection['learner_id']];
                    $old = $previous->get($learner->id);
                    $participantId = $old->id ?? (string) Str::uuid();
                    $participantIds[$learner->id] = $participantId;

                    $participants[] = [
                        'id' => $participantId, 'roster_id' => $rosterId,
                        'school_id' => $schoolId, 'exam_id' => $examId,
                        'learner_id' => $learner->id,
                        'grade_id' => $old->grade_id ?? $learner->grade_id,
                        'stream_id' => $old ? $old->stream_id : $learner->stream_id,
                        'created_at' => $old->created_at ?? $now,
                        'updated_at' => $now,
                    ];
                }

                // Persist parents before inserting their expected-paper rows.
                DB::table('exam_roster_learners')->insert($participants);

                $requirements = [];

                foreach ($batch as $selection) {
                    $participantId = $participantIds[$selection['learner_id']];

                    foreach (array_unique($selection['exam_learning_area_ids']) as $areaId) {
                        $area = $areas[$areaId];

                        foreach ($papers[$areaId] as $paper) {
                            $requirements[] = [
                                'id' => (string) Str::uuid(),
                                'roster_learner_id' => $participantId,
                                ...($paperExamIdentity ? ['exam_id' => $examId] : []),
                                'exam_learning_area_id' => $area->id,
                                'learning_area_id' => $area->learning_area_id,
                                'paper_id' => $paper->id,
                                'paper_number' => $paper->paper_number,
                                'maximum_marks' => $paper->max_marks,
                                'expected_paper_count' => $area->number_of_papers,
                                'learning_area_total_marks' => $area->total_marks,
                                'created_at' => $now, 'updated_at' => $now,
                            ];

                            if (count($requirements) === 500) {
                                DB::table('exam_roster_papers')->insert($requirements);
                                $requirements = [];
                            }
                        }
                    }
                }

                if ($requirements !== []) {
                    DB::table('exam_roster_papers')->insert($requirements);
                }
            }

            $this->lifecycle->invalidateOutputs($exam);

            return [
                'id' => $rosterId, 'exam_id' => $examId,
                'version' => $version, 'learner_count' => $learners->count(),
            ];
        });
    }
}
