<?php

namespace App\Services\Assessment;

use App\Models\Exam;
use App\Models\Grade;
use App\Models\Learner;
use App\Models\LearningAreaResult;
use App\Models\MeritList;
use App\Models\PathwayRecommendation;
use App\Models\ReportCard;
use App\Models\ReportCardLearningArea;
use App\Models\Stream;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReportCardService
{
    private const RELATIONS = ['learner', 'exam', 'academicYear', 'term', 'meritList', 'grade', 'stream', 'overallGradingSystem', 'overallGradingScale', 'pathwayRecommendation', 'generatedBy', 'publishedBy', 'learningAreas.learningArea', 'learningAreas.gradingScale'];

    public function __construct(private readonly ExamResultLifecycleService $lifecycle) {}

    public function generateSummary(
        string $schoolId,
        string $examId,
        ?string $learnerId,
        ?string $gradeId,
        ?string $streamId,
        string $userId,
        array $comments = []
    ): array {
        $cards = $this->generate(
            $schoolId, $examId, $learnerId, $gradeId, $streamId,
            $userId, $comments, false
        );

        return [
            'exam_id' => $examId,
            'generated_count' => $cards->count(),
            'status' => 'generated',
        ];
    }

    public function generate(string $schoolId, string $examId, ?string $learnerId, ?string $gradeId, ?string $streamId, string $userId, array $comments = [], bool $loadDetails = true): Collection
    {
        return DB::transaction(function () use ($schoolId, $examId, $learnerId, $gradeId, $streamId, $userId, $comments, $loadDetails) {
            $exam = $this->lifecycle->lockExam($schoolId, $examId);
            $this->context($schoolId, $examId, $learnerId, $gradeId, $streamId);
            $this->lifecycle->assertProcessedResultsFresh($exam);
            app(ExamCompletenessService::class)->assertComplete($schoolId, $examId);
            app(ExamCompletenessService::class)->assertOutputCoverage(
                'merit_lists', $schoolId, $examId, $learnerId, $gradeId, $streamId
            );
            $merits = MeritList::current()->where('school_id', $schoolId)->where('exam_id', $examId)->whereIn('status', ['generated', 'published'])->with('learner.grade.educationLevel')
                ->when($learnerId, fn ($q) => $q->where('learner_id', $learnerId))->when($gradeId, fn ($q) => $q->where('grade_id', $gradeId))->when($streamId, fn ($q) => $q->where('stream_id', $streamId))->lockForUpdate()->get();
            if ($merits->isEmpty()) {
                throw ValidationException::withMessages(['merit_list' => 'No generated or published merit-list rows were found.']);
            }
            $learnerIds = $merits->pluck('learner_id');

            // Include deleted rows: never resurrect a published card.
            $existingCards = ReportCard::query()
                ->where('school_id', $schoolId)
                ->where('exam_id', $examId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $hasPublishedCard = $existingCards->contains(
                function (ReportCard $card) use ($learnerIds, $learnerId, $gradeId, $streamId): bool {
                    $matchesSelection = ($learnerId === null || $card->learner_id === $learnerId)
                        && ($gradeId === null || $card->grade_id === $gradeId)
                        && ($streamId === null || $card->stream_id === $streamId);

                    return $card->status === 'published'
                        && ($matchesSelection || $learnerIds->contains($card->learner_id));
                }
            );

            if ($hasPublishedCard) {
                throw ValidationException::withMessages([
                    'report_card' => 'Published report cards cannot be regenerated.',
                ]);
            }

            $existingCards = $existingCards->keyBy('learner_id');
            // Load processed results only for the next group of report cards.
            $results = collect();
            $resultBatchIds = $learnerIds->values()->chunk(250)->values();
            $resultBatchIndex = 0;
            $batchMembers = collect();
            $sourceLearners = $merits->mapWithKeys(
                fn ($merit) => [$merit->learner_id => $merit->learner]
            );
            $existingDetails = collect();
            $batchAttendance = collect();
            $cohortSizes = [];
            $attendanceAvailable = DB::getSchemaBuilder()->hasTable('learner_attendance')
                && DB::getSchemaBuilder()->hasTable('attendance_statuses');

            $generated = $merits->map(function ($merit) use ($schoolId, $exam, &$results, &$existingDetails, &$batchMembers, $sourceLearners, $resultBatchIds, &$resultBatchIndex, &$cohortSizes, &$batchAttendance, $attendanceAvailable, $userId, $comments, $existingCards) {
                if ($results->isEmpty()) {
                    $batchIds = $resultBatchIds->get($resultBatchIndex++);
                    if ($batchIds === null) {
                        throw new \LogicException('Report-card result batches were exhausted.');
                    }

                    $batchMembers = app(ExamMembershipService::class)->learnersAtExam(
                        $schoolId, $exam->id,
                        $batchIds->map(fn ($id) => $sourceLearners->get($id))
                    );

                    $batchAttendance = $this->attendanceForLearners(
                        $schoolId, $batchIds->all(),
                        $exam->start_date, $exam->end_date,
                        $attendanceAvailable
                    );

                    $batchCardIds = $existingCards->toBase()
                        ->only($batchIds->all())->pluck('id');

                    // Include archived details so regeneration preserves identity.
                    $existingDetails = $batchCardIds->isEmpty()
                        ? collect()
                        : ReportCardLearningArea::query()
                            ->whereIn('report_card_id', $batchCardIds)
                            ->get()->keyBy(
                                fn ($detail) => $detail->report_card_id.'|'.$detail->learning_area_id
                            );

                    $results = LearningAreaResult::current()
                        ->where('school_id', $schoolId)
                        ->where('exam_id', $exam->id)
                        ->whereIn('learner_id', $batchIds)
                        ->where('processing_status', 'processed')
                        ->with('gradingScale')
                        ->get()->groupBy('learner_id');

                    if ($batchIds->contains(fn ($id) => ! $results->has($id))) {
                        throw ValidationException::withMessages([
                            'learning_area_results' => 'Every learner requires processed learning-area results.',
                        ]);
                    }
                }

                $learner = $batchMembers->get($merit->learner_id);
                if (! $learner) {
                    throw ValidationException::withMessages([
                        'learner_id' => 'The learner is not included in this exam roster.',
                    ]);
                }
                $areas = $results[$learner->id];

                if ($merit->grading_snapshot === null
                    || $areas->contains(fn (LearningAreaResult $area) => $area->grading_snapshot === null)) {
                    throw ValidationException::withMessages([
                        'grading_snapshot' => 'Processed results and merit entries require captured grading before report-card generation.',
                    ]);
                }
                $identity = ['school_id' => $schoolId, 'exam_id' => $exam->id, 'learner_id' => $learner->id];
                $card = $existingCards->get($learner->id) ?? new ReportCard($identity);
                if (! $card->exists) {
                    $card->id = (string) Str::uuid();
                }
                $attendance = $batchAttendance->get($learner->id)
                    ?? $this->nullAttendance();
                $pathway = $this->pathway($learner, $exam->academic_year_id);
                $cohortKey = $learner->grade_id.'|'.($learner->stream_id ?? '');
                $totalLearners = $cohortSizes[$cohortKey]
                    ??= $this->totalLearners($schoolId, $exam->id, $learner);
                $card->fill(['academic_year_id' => $exam->academic_year_id, 'term_id' => $exam->term_id, 'merit_list_id' => $merit->id, 'has_absence' => $areas->contains(fn (LearningAreaResult $area) => (bool) $area->is_absent), 'grade_id' => $learner->grade_id, 'stream_id' => $learner->stream_id, 'overall_score' => $merit->total_score, 'maximum_marks' => $merit->maximum_marks, 'average_percentage' => $merit->average_percentage, 'grading_snapshot' => $merit->grading_snapshot, 'overall_grade' => $merit->grading_snapshot['grade_code'], 'overall_grading_system_id' => $merit->overall_grading_system_id, 'overall_grading_scale_id' => $merit->overall_grading_scale_id, 'total_points' => $merit->total_points, 'stream_position' => $merit->stream_position, 'grade_position' => $merit->grade_position, 'school_position' => $merit->school_position, 'total_learners' => $totalLearners, 'pathway_recommendation_id' => $pathway?->id, 'pathway_recommendation' => $pathway?->recommended_pathway, 'status' => 'generated', 'generated_by' => $userId, 'generated_at' => now(), 'published_by' => null, 'published_at' => null, 'is_deleted' => false, 'deleted_at' => null, 'deleted_by' => null] + $attendance);
                if (array_key_exists('class_teacher_comment', $comments)) {
                    $card->class_teacher_comment = $comments['class_teacher_comment'];
                } if (array_key_exists('principal_comment', $comments)) {
                    $card->principal_comment = $comments['principal_comment'];
                } $card->save();
                $current = [];
                foreach ($areas as $area) {
                    $current[] = $area->learning_area_id;
                    $detail = $existingDetails->get($card->id.'|'.$area->learning_area_id)
                        ?? new ReportCardLearningArea([
                            'report_card_id' => $card->id,
                            'learning_area_id' => $area->learning_area_id,
                        ]);
                    if (! $detail->exists) {
                        $detail->id = (string) Str::uuid();
                    } $detail->fill(['learning_area_result_id' => $area->id, 'is_absent' => (bool) $area->is_absent, 'score' => $area->marks_obtained, 'marks_obtained' => $area->marks_obtained, 'maximum_marks' => $area->maximum_marks, 'percentage' => $area->percentage, 'grading_system_id' => $area->grading_system_id, 'grading_scale_id' => $area->grading_scale_id, 'grading_snapshot' => $area->grading_snapshot, 'grade_code' => $area->grading_snapshot['grade_code'], 'points' => $area->grading_snapshot['points'], 'is_deleted' => false, 'deleted_at' => null, 'deleted_by' => null])->save();
                }
                ReportCardLearningArea::current()->where('report_card_id', $card->id)->whereNotIn('learning_area_id', $current)->update(['is_deleted' => true, 'deleted_at' => now(), 'deleted_by' => $userId]);

                $results->forget($learner->id);

                return $card;
            });

            unset($results, $merits, $existingCards, $existingDetails, $batchMembers, $sourceLearners);

            $output = new \Illuminate\Database\Eloquent\Collection($generated->all());
            unset($generated, $resultBatchIds, $learnerIds);

            if (! $loadDetails) {
                return $output;
            }

            // Bound temporary relationship hydration structures.
            foreach ($output->chunk(250) as $batch) {
                $batch->load(self::RELATIONS);
            }

            return $output;
        });
    }

    public function updateComments(string $schoolId, string $id, array $data): ReportCard
    {
        return DB::transaction(function () use ($schoolId, $id, $data) {
            $candidate = ReportCard::current()
                ->where('school_id', $schoolId)
                ->find($id);

            if (! $candidate) {
                throw ValidationException::withMessages([
                    'report_card' => 'Report card not found for this school.',
                ]);
            }

            $this->lifecycle->lockExam($schoolId, $candidate->exam_id);
            $card = ReportCard::current()->where('school_id', $schoolId)->lockForUpdate()->find($id);
            if (! $card) {
                throw ValidationException::withMessages([
                    'report_card' => 'Report card not found for this school.',
                ]);
            }

            if ($card->status === 'published') {
                throw ValidationException::withMessages([
                    'report_card' => 'Published report card comments cannot be changed.',
                ]);
            }

            foreach (['class_teacher_comment', 'principal_comment'] as $f) {
                if (array_key_exists($f, $data)) {
                    $card->$f = $data[$f];
                }
            } $card->save();
            foreach ($data['learning_areas'] ?? [] as $item) {
                $detail = ReportCardLearningArea::current()->where('report_card_id', $card->id)->where('id', $item['id'])->first();
                if (! $detail) {
                    throw ValidationException::withMessages(['learning_areas' => 'A learning-area row does not belong to this report card.']);
                } $detail->update(['teacher_comment' => $item['teacher_comment'] ?? null]);
            }

            return $card->load(self::RELATIONS);
        });
    }

    public function publish(string $schoolId, string $examId, ?string $learnerId, ?string $gradeId, ?string $streamId, string $userId): Collection
    {
        return DB::transaction(function () use ($schoolId, $examId, $learnerId, $gradeId, $streamId, $userId) {
            $exam = $this->lifecycle->lockExam($schoolId, $examId);
            $this->context($schoolId, $examId, $learnerId, $gradeId, $streamId);
            $this->lifecycle->assertProcessedResultsFresh($exam);
            app(ExamCompletenessService::class)->assertComplete($schoolId, $examId);
            app(ExamCompletenessService::class)->assertOutputCoverage(
                'merit_lists', $schoolId, $examId, $learnerId, $gradeId, $streamId
            );
            $q = ReportCard::current()->where('school_id', $schoolId)->where('exam_id', $examId)->where('status', 'generated')->when($learnerId, fn ($x) => $x->where('learner_id', $learnerId))->when($gradeId, fn ($x) => $x->where('grade_id', $gradeId))->when($streamId, fn ($x) => $x->where('stream_id', $streamId));
            app(ExamCompletenessService::class)->assertOutputCoverage(
                'report_cards', $schoolId, $examId, $learnerId, $gradeId, $streamId
            );
            $cards = $q->lockForUpdate()->get();
            if ($cards->isEmpty()) {
                throw ValidationException::withMessages(['report_cards' => 'No generated report cards were found for publishing.']);
            }

            if ($cards->contains(fn (ReportCard $card) => $card->grading_snapshot === null)
                || ReportCardLearningArea::current()
                    ->whereIn('report_card_id', $cards->pluck('id'))
                    ->whereNull('grading_snapshot')->exists()
                || MeritList::current()
                    ->where('school_id', $schoolId)->where('exam_id', $examId)
                    ->whereIn('learner_id', $cards->pluck('learner_id'))
                    ->whereNull('grading_snapshot')->exists()
                || LearningAreaResult::current()
                    ->where('school_id', $schoolId)->where('exam_id', $examId)
                    ->where('processing_status', 'processed')
                    ->whereNull('grading_snapshot')->exists()) {
                throw ValidationException::withMessages([
                    'grading_snapshot' => 'Reprocess legacy results and regenerate merit entries and report cards before publication.',
                ]);
            }

            $cards->each->update(['status' => 'published', 'published_by' => $userId, 'published_at' => now()]);

            return $cards->load(self::RELATIONS);
        });
    }

    private function context(string $schoolId, string $examId, ?string $learnerId, ?string $gradeId, ?string $streamId): Exam
    {
        $exam = Exam::current()->whereKey($examId)->where('school_id', $schoolId)->first();
        if (! $exam) {
            throw ValidationException::withMessages(['exam_id' => 'The exam does not belong to this school.']);
        } if ($learnerId && ! Learner::query()->whereKey($learnerId)->where('school_id', $schoolId)
            ->where(function ($query) use ($schoolId, $examId, $learnerId) {
                $query->where('is_deleted', false)
                    ->orWhereExists(function ($participants) use ($schoolId, $examId, $learnerId) {
                        $participants->selectRaw('1')->from('exam_roster_learners')
                            ->where('school_id', $schoolId)
                            ->where('exam_id', $examId)
                            ->where('learner_id', $learnerId);
                    });
            })->exists()) {
            throw ValidationException::withMessages(['learner_id' => 'The learner does not belong to this school.']);
        } if ($gradeId && ! Grade::whereKey($gradeId)->where('school_id', $schoolId)->exists()) {
            throw ValidationException::withMessages(['grade_id' => 'The grade does not belong to this school.']);
        } if ($streamId && ! Stream::whereKey($streamId)->where('school_id', $schoolId)->when($gradeId, fn ($q) => $q->where('grade_id', $gradeId))->exists()) {
            throw ValidationException::withMessages(['stream_id' => 'The stream does not belong to this school or grade.']);
        }

        return $exam;
    }

    private function totalLearners(string $schoolId, string $examId, Learner $learner): int
    {
        return DB::table('exam_roster_learners')
            ->where('school_id', $schoolId)
            ->where('exam_id', $examId)
            ->where('grade_id', $learner->grade_id)
            ->when(
                $learner->stream_id,
                fn ($query) => $query->where('stream_id', $learner->stream_id)
            )
            ->count();
    }

    private function attendanceForLearners(
        string $schoolId,
        array $learnerIds,
        $start,
        $end,
        bool $available
    ): Collection {
        if (! $available || $learnerIds === []) {
            return collect();
        }

        return DB::table('learner_attendance as la')
            ->join('attendance_statuses as s', 's.id', '=', 'la.attendance_status_id')
            ->where('la.school_id', $schoolId)
            ->whereIn('la.learner_id', $learnerIds)
            ->when($start, fn ($query) => $query
                ->whereDate('la.attendance_date', '>=', $start))
            ->when($end, fn ($query) => $query
                ->whereDate('la.attendance_date', '<=', $end))
            ->select('la.learner_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN UPPER(s.status_code) IN ('P', 'PRESENT') THEN 1 ELSE 0 END) as present")
            ->selectRaw("SUM(CASE WHEN UPPER(s.status_code) IN ('A', 'ABSENT') THEN 1 ELSE 0 END) as absent")
            ->selectRaw("SUM(CASE WHEN UPPER(s.status_code) IN ('L', 'LATE') THEN 1 ELSE 0 END) as late")
            ->groupBy('la.learner_id')
            ->get()
            ->mapWithKeys(function ($row) {
                $total = (int) $row->total;
                $present = (int) $row->present;
                $late = (int) $row->late;

                return [$row->learner_id => [
                    'attendance_present' => $present,
                    'attendance_absent' => (int) $row->absent,
                    'attendance_late' => $late,
                    'attendance_total_sessions' => $total,
                    'attendance_percentage' => round(
                        (($present + $late) / $total) * 100, 2
                    ),
                ]];
            });
    }

    private function nullAttendance(): array
    {
        return ['attendance_present' => null, 'attendance_absent' => null, 'attendance_late' => null, 'attendance_total_sessions' => null, 'attendance_percentage' => null];
    }

    private function pathway(Learner $learner, string $yearId): ?PathwayRecommendation
    {
        if (! str_contains(strtolower((string) $learner->grade?->educationLevel?->level_name), 'junior')) {
            return null;
        }

        return PathwayRecommendation::where('learner_id', $learner->id)->where('academic_year_id', $yearId)->orderByDesc('recommendation_date')->orderByDesc('created_at')->first();
    }
}
