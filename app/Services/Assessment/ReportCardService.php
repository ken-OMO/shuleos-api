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

    public function generate(string $schoolId, string $examId, ?string $learnerId, ?string $gradeId, ?string $streamId, string $userId, array $comments = []): Collection
    {
        return DB::transaction(function () use ($schoolId, $examId, $learnerId, $gradeId, $streamId, $userId, $comments) {
            $exam = $this->context($schoolId, $examId, $learnerId, $gradeId, $streamId);
            $merits = MeritList::current()->where('school_id', $schoolId)->where('exam_id', $examId)->whereIn('status', ['generated', 'published'])->with('learner.grade.educationLevel')
                ->when($learnerId, fn ($q) => $q->where('learner_id', $learnerId))->when($gradeId, fn ($q) => $q->where('grade_id', $gradeId))->when($streamId, fn ($q) => $q->where('stream_id', $streamId))->lockForUpdate()->get();
            if ($merits->isEmpty()) {
                throw ValidationException::withMessages(['merit_list' => 'No generated or published merit-list rows were found.']);
            }
            $learnerIds = $merits->pluck('learner_id');
            $results = LearningAreaResult::current()->where('school_id', $schoolId)->where('exam_id', $examId)->whereIn('learner_id', $learnerIds)->where('processing_status', 'processed')->with('gradingScale')->get()->groupBy('learner_id');
            $missing = $learnerIds->reject(fn ($id) => $results->has($id));
            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages(['learning_area_results' => 'Every learner requires processed learning-area results.']);
            }

            return $merits->map(function ($merit) use ($schoolId, $exam, $results, $userId, $comments) {
                $learner = $merit->learner;
                $areas = $results[$learner->id];
                $identity = ['school_id' => $schoolId, 'exam_id' => $exam->id, 'learner_id' => $learner->id];
                $card = ReportCard::query()->firstOrNew($identity);
                if (! $card->exists) {
                    $card->id = (string) Str::uuid();
                }
                $attendance = $this->attendance($schoolId, $learner->id, $exam->start_date, $exam->end_date);
                $pathway = $this->pathway($learner, $exam->academic_year_id);
                $card->fill(['academic_year_id' => $exam->academic_year_id, 'term_id' => $exam->term_id, 'merit_list_id' => $merit->id, 'grade_id' => $learner->grade_id, 'stream_id' => $learner->stream_id, 'overall_score' => $merit->total_score, 'maximum_marks' => $merit->maximum_marks, 'average_percentage' => $merit->average_percentage, 'overall_grade' => $merit->overallGradingScale?->grade_code, 'overall_grading_system_id' => $merit->overall_grading_system_id, 'overall_grading_scale_id' => $merit->overall_grading_scale_id, 'total_points' => $merit->total_points, 'stream_position' => $merit->stream_position, 'grade_position' => $merit->grade_position, 'school_position' => $merit->school_position, 'total_learners' => $this->totalLearners($schoolId, $learner), 'pathway_recommendation_id' => $pathway?->id, 'pathway_recommendation' => $pathway?->recommended_pathway, 'status' => 'generated', 'generated_by' => $userId, 'generated_at' => now(), 'published_by' => null, 'published_at' => null, 'is_deleted' => false, 'deleted_at' => null, 'deleted_by' => null] + $attendance);
                if (array_key_exists('class_teacher_comment', $comments)) {
                    $card->class_teacher_comment = $comments['class_teacher_comment'];
                } if (array_key_exists('principal_comment', $comments)) {
                    $card->principal_comment = $comments['principal_comment'];
                } $card->save();
                $current = [];
                foreach ($areas as $area) {
                    $current[] = $area->learning_area_id;
                    $detail = ReportCardLearningArea::query()->firstOrNew(['report_card_id' => $card->id, 'learning_area_id' => $area->learning_area_id]);
                    if (! $detail->exists) {
                        $detail->id = (string) Str::uuid();
                    } $detail->fill(['learning_area_result_id' => $area->id, 'score' => $area->marks_obtained, 'marks_obtained' => $area->marks_obtained, 'maximum_marks' => $area->maximum_marks, 'percentage' => $area->percentage, 'grading_system_id' => $area->grading_system_id, 'grading_scale_id' => $area->grading_scale_id, 'grade_code' => $area->gradingScale?->grade_code, 'points' => $area->gradingScale?->points, 'is_deleted' => false, 'deleted_at' => null, 'deleted_by' => null])->save();
                }
                ReportCardLearningArea::current()->where('report_card_id', $card->id)->whereNotIn('learning_area_id', $current)->update(['is_deleted' => true, 'deleted_at' => now(), 'deleted_by' => $userId]);

                return $card->load(self::RELATIONS);
            });
        });
    }

    public function updateComments(string $schoolId, string $id, array $data): ReportCard
    {
        return DB::transaction(function () use ($schoolId, $id, $data) {
            $card = ReportCard::current()->where('school_id', $schoolId)->lockForUpdate()->find($id);
            if (! $card) {
                throw ValidationException::withMessages(['report_card' => 'Report card not found for this school.']);
            } foreach (['class_teacher_comment', 'principal_comment'] as $f) {
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
            $this->context($schoolId, $examId, $learnerId, $gradeId, $streamId);
            $q = ReportCard::current()->where('school_id', $schoolId)->where('exam_id', $examId)->where('status', 'generated')->when($learnerId, fn ($x) => $x->where('learner_id', $learnerId))->when($gradeId, fn ($x) => $x->where('grade_id', $gradeId))->when($streamId, fn ($x) => $x->where('stream_id', $streamId));
            $cards = $q->lockForUpdate()->get();
            if ($cards->isEmpty()) {
                throw ValidationException::withMessages(['report_cards' => 'No generated report cards were found for publishing.']);
            } $cards->each->update(['status' => 'published', 'published_by' => $userId, 'published_at' => now()]);

            return $cards->load(self::RELATIONS);
        });
    }

    private function context(string $schoolId, string $examId, ?string $learnerId, ?string $gradeId, ?string $streamId): Exam
    {
        $exam = Exam::current()->whereKey($examId)->where('school_id', $schoolId)->first();
        if (! $exam) {
            throw ValidationException::withMessages(['exam_id' => 'The exam does not belong to this school.']);
        } if ($learnerId && ! Learner::query()->whereKey($learnerId)->where('school_id', $schoolId)->where('is_deleted', false)->exists()) {
            throw ValidationException::withMessages(['learner_id' => 'The learner does not belong to this school.']);
        } if ($gradeId && ! Grade::whereKey($gradeId)->where('school_id', $schoolId)->exists()) {
            throw ValidationException::withMessages(['grade_id' => 'The grade does not belong to this school.']);
        } if ($streamId && ! Stream::whereKey($streamId)->where('school_id', $schoolId)->when($gradeId, fn ($q) => $q->where('grade_id', $gradeId))->exists()) {
            throw ValidationException::withMessages(['stream_id' => 'The stream does not belong to this school or grade.']);
        }

        return $exam;
    }

    private function totalLearners(string $schoolId, Learner $learner): int
    {
        return Learner::query()->where('school_id', $schoolId)->where('active', true)->where('is_deleted', false)->when($learner->stream_id, fn ($q) => $q->where('stream_id', $learner->stream_id), fn ($q) => $q->where('grade_id', $learner->grade_id))->count();
    }

    private function attendance(string $schoolId, string $learnerId, $start, $end): array
    {
        if (! DB::getSchemaBuilder()->hasTable('learner_attendance') || ! DB::getSchemaBuilder()->hasTable('attendance_statuses')) {
            return $this->nullAttendance();
        } $rows = DB::table('learner_attendance as la')->join('attendance_statuses as s', 's.id', '=', 'la.attendance_status_id')->where('la.school_id', $schoolId)->where('la.learner_id', $learnerId)->when($start, fn ($q) => $q->whereDate('la.attendance_date', '>=', $start))->when($end, fn ($q) => $q->whereDate('la.attendance_date', '<=', $end))->pluck('s.status_code')->map(fn ($x) => strtoupper($x));
        if ($rows->isEmpty()) {
            return $this->nullAttendance();
        } $present = $rows->filter(fn ($x) => in_array($x, ['P', 'PRESENT']))->count();
        $absent = $rows->filter(fn ($x) => in_array($x, ['A', 'ABSENT']))->count();
        $late = $rows->filter(fn ($x) => in_array($x, ['L', 'LATE']))->count();

        return ['attendance_present' => $present, 'attendance_absent' => $absent, 'attendance_late' => $late, 'attendance_total_sessions' => $rows->count(), 'attendance_percentage' => round((($present + $late) / $rows->count()) * 100, 2)];
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
