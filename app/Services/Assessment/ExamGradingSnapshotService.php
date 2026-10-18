<?php

namespace App\Services\Assessment;

use App\Models\Learner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExamGradingSnapshotService
{
    /**
     * The caller must retain its transaction through the result/output writes.
     * Rules are captured on first use, not reconstructed as historical data.
     */
    public function calculate(
        string $schoolId,
        string $examId,
        Learner $learner,
        float $percentage,
        ?string $actorId = null
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Exam grading snapshots require a transaction.');
        }

        if (! is_finite($percentage) || $percentage < 0 || $percentage > 100) {
            throw ValidationException::withMessages([
                'percentage' => 'The percentage must be finite and between zero and 100.',
            ]);
        }

        $lifecycle = app(ExamResultLifecycleService::class);
        $exam = $lifecycle->lockExam($schoolId, $examId);
        $member = app(ExamMembershipService::class)
            ->learnerAtExam($schoolId, $examId, $learner);
        $levelId = $member->grade?->education_level_id;

        if (! $levelId) {
            throw ValidationException::withMessages([
                'learner_id' => 'The exam membership does not resolve to an education level.',
            ]);
        }

        $snapshot = DB::table('exam_grading_snapshots')
            ->where('school_id', $schoolId)
            ->where('exam_id', $examId)
            ->where('education_level_id', $levelId)
            ->lockForUpdate()->first();

        if ($snapshot) {
            $rules = json_decode($snapshot->rules, true, 512, JSON_THROW_ON_ERROR);
            $snapshotId = $snapshot->id;
        } else {
            // Never initialize new rules behind already published outputs.
            $lifecycle->assertMutable($exam);

            if ($actorId !== null && ! DB::table('users')
                ->where('id', $actorId)
                ->where('school_id', $schoolId)
                ->where('active', true)
                ->where('is_deleted', false)->exists()) {
                throw ValidationException::withMessages([
                    'user_id' => 'An active user from this school is required to capture grading rules.',
                ]);
            }

            $systems = DB::table('grading_systems')
                ->where('school_id', $schoolId)
                ->where('education_level_id', $levelId)
                ->where('active', true)
                ->orderBy('id')->lockForUpdate()->get();

            if ($systems->count() !== 1) {
                throw ValidationException::withMessages([
                    'grading_system' => 'Exactly one active grading system is required for the exam education level.',
                ]);
            }

            $system = $systems->first();
            $scales = DB::table('grading_scales')
                ->where('grading_system_id', $system->id)
                ->orderBy('sort_order')->orderBy('id')
                ->lockForUpdate()->get();

            if ($scales->isEmpty()) {
                throw ValidationException::withMessages([
                    'grading_scale' => 'The grading system requires configured scales.',
                ]);
            }

            $capturedScales = [];
            foreach ($scales as $scale) {
                if (! is_numeric($scale->min_score)
                    || ! is_numeric($scale->max_score)
                    || ! is_finite((float) $scale->min_score)
                    || ! is_finite((float) $scale->max_score)
                    || (float) $scale->min_score < 0
                    || (float) $scale->max_score > 100
                    || (float) $scale->min_score > (float) $scale->max_score) {
                    throw ValidationException::withMessages([
                        'grading_scale' => 'Grading scale boundaries must be valid percentages.',
                    ]);
                }

                $capturedScales[] = [
                    'id' => $scale->id,
                    'grade_code' => $scale->grade_code,
                    'grade_description' => $scale->grade_description,
                    'min_score' => (float) $scale->min_score,
                    'max_score' => (float) $scale->max_score,
                    'points' => $scale->points === null ? null : (int) $scale->points,
                    'sort_order' => (int) $scale->sort_order,
                ];
            }

            $rules = [
                'format_version' => 1,
                'education_level_id' => $levelId,
                'grading_system_id' => $system->id,
                'grading_name' => $system->grading_name,
                'uses_points' => (bool) $system->uses_points,
                'uses_marks' => (bool) $system->uses_marks,
                'scales' => $capturedScales,
            ];

            // Validate the requested award before persisting a new rules snapshot.
            $this->matchingScale($rules, $percentage);

            $snapshotId = (string) Str::uuid();
            DB::table('exam_grading_snapshots')->insert([
                'id' => $snapshotId,
                'school_id' => $schoolId,
                'exam_id' => $examId,
                'education_level_id' => $levelId,
                'captured_by' => $actorId,
                'captured_at' => now(),
                'rules' => json_encode($rules, JSON_THROW_ON_ERROR),
            ]);
        }

        $scale = $this->matchingScale($rules, $percentage);

        return [
            'format_version' => 1,
            'rules_snapshot_id' => $snapshotId,
            'grading_system_id' => $rules['grading_system_id'],
            'grading_scale_id' => $scale['id'],
            'grade_code' => $scale['grade_code'],
            'grade_description' => $scale['grade_description'],
            'points' => $scale['points'],
        ];
    }

    private function matchingScale(array $rules, float $percentage): array
    {
        $matches = array_values(array_filter(
            $rules['scales'],
            fn (array $scale) => $percentage >= $scale['min_score']
                && $percentage <= $scale['max_score']
        ));

        if (count($matches) !== 1) {
            throw ValidationException::withMessages([
                'grading_scale' => 'The percentage must match exactly one captured grading scale.',
            ]);
        }

        return $matches[0];
    }
}
