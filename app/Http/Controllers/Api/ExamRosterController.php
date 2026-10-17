<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Services\Assessment\ExamCompletenessService;
use App\Services\Assessment\ExamResultLifecycleService;
use App\Services\Assessment\ExamRosterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExamRosterController extends BaseCrudController
{
    public function __construct(
        private readonly ExamRosterService $rosters,
        private readonly ExamCompletenessService $reports,
        private readonly ExamResultLifecycleService $lifecycle
    ) {}

    public function show(Request $request, string $id)
    {
        $schoolId = $this->school($request);

        $data = DB::transaction(function () use ($schoolId, $id) {
            $this->lifecycle->lockExam($schoolId, $id);
            $roster = DB::table('exam_rosters')
                ->where('school_id', $schoolId)->where('exam_id', $id)->first();

            if (! $roster) {
                return ['exam_id' => $id, 'roster_configured' => false, 'version' => 0, 'learners' => []];
            }

            $learners = DB::table('exam_roster_learners')
                ->where('roster_id', $roster->id)->orderBy('learner_id')->get();
            $papers = DB::table('exam_roster_papers')
                ->whereIn('roster_learner_id', $learners->pluck('id'))
                ->orderBy('learning_area_id')->orderBy('paper_number')->orderBy('paper_id')
                ->get()->groupBy('roster_learner_id');

            return [
                'exam_id' => $id,
                'roster_configured' => true,
                'version' => (int) $roster->version,
                'learners' => $learners->map(fn ($learner) => [
                    'learner_id' => $learner->learner_id,
                    'grade_id' => $learner->grade_id,
                    'stream_id' => $learner->stream_id,
                    'exam_learning_area_ids' => $papers->get($learner->id, collect())
                        ->pluck('exam_learning_area_id')->unique()->values()->all(),
                    'papers' => $papers->get($learner->id, collect())->map(fn ($paper) => [
                        'paper_id' => $paper->paper_id,
                        'exam_learning_area_id' => $paper->exam_learning_area_id,
                        'learning_area_id' => $paper->learning_area_id,
                        'paper_number' => (int) $paper->paper_number,
                        'maximum_marks' => $paper->maximum_marks,
                        'expected_paper_count' => (int) $paper->expected_paper_count,
                        'learning_area_total_marks' => $paper->learning_area_total_marks,
                    ])->values()->all(),
                ])->values()->all(),
            ];
        });

        return $this->success($data, 'Exam roster retrieved successfully.');
    }

    public function update(Request $request, string $id)
    {
        $data = $request->validate([
            'version' => 'required|integer|min:0',
            'learners' => 'required|array|min:1|max:2000',
            'learners.*' => 'required|array:learner_id,exam_learning_area_ids',
            'learners.*.learner_id' => 'required|uuid|distinct',
            'learners.*.exam_learning_area_ids' => 'required|array|min:1|max:50',
            'learners.*.exam_learning_area_ids.*' => 'required|uuid',
        ]);

        $result = $this->rosters->replace(
            $this->school($request), $id, (string) $request->user()->id,
            (int) $data['version'], $data['learners']
        );

        return $this->success($result, 'Exam roster saved successfully.');
    }

    public function completeness(Request $request, string $id)
    {
        return $this->success(
            $this->reports->report($this->school($request), $id),
            'Exam completeness retrieved successfully.'
        );
    }

    private function school(Request $request): string
    {
        $schoolId = $request->attributes->get('tenant_school_id');
        abort_unless($schoolId, 403, 'School context not found.');

        return (string) $schoolId;
    }
}
