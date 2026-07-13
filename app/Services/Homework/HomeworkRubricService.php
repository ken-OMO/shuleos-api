<?php

namespace App\Services\Homework;

use App\Models\HomeworkRubric;
use App\Models\HomeworkRubricCriterion;
use App\Models\HomeworkRubricLevel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HomeworkRubricService
{
    public function __construct(private HomeworkAssignmentService $assignments) {}

    public function save(User $u, string $id, array $data): HomeworkRubric
    {
        return DB::transaction(function () use ($u, $id, $data) {
            $a = $this->assignments->ownQuery($u)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($a->grading_mode, ['rubric', 'competency'], true), 422);
            abort_unless(in_array($a->status, ['draft', 'scheduled'], true), 409);
            if (DB::table('homework_submission_marks')->where('assignment_id', $a->id)->exists()) {
                throw ValidationException::withMessages(['rubric' => 'Rubric is immutable after marking begins.']);
            }$names = [];
            $total = 0;
            foreach ($data['criteria'] as $c) {
                $key = mb_strtolower(trim($c['criterion']));
                if (in_array($key, $names, true)) {
                    throw ValidationException::withMessages(['criteria' => 'Criterion names must be unique.']);
                }$names[] = $key;
                $max = (float) ($c['maximum_points'] ?? 0);
                $total += $max;
                $levels = [];
                foreach ($c['levels'] ?? [] as $l) {
                    $lk = mb_strtolower(trim($l['level_name']));
                    if (in_array($lk, $levels, true)) {
                        throw ValidationException::withMessages(['levels' => 'Level names must be unique per criterion.']);
                    }$levels[] = $lk;
                    if (isset($l['points']) && (float) $l['points'] > $max) {
                        throw ValidationException::withMessages(['levels' => 'Level points cannot exceed criterion maximum.']);
                    }
                }
            }if ($a->total_marks !== null && abs($total - (float) $a->total_marks) > 0.001) {
                throw ValidationException::withMessages(['criteria' => 'Criterion total must equal assignment total marks.']);
            }$rubric = HomeworkRubric::where('assignment_id', $a->id)->first();
            if ($rubric) {
                DB::table('homework_rubric_levels')->whereIn('criterion_id', DB::table('homework_rubric_criteria')->where('rubric_id', $rubric->id)->select('id'))->delete();
                DB::table('homework_rubric_criteria')->where('rubric_id', $rubric->id)->delete();
                $rubric->update(['title' => $data['title'], 'description' => $data['description'] ?? null, 'total_points' => $total]);
            } else {
                $rubric = HomeworkRubric::create(['id' => (string) Str::uuid(), 'school_id' => $u->school_id, 'assignment_id' => $a->id, 'title' => $data['title'], 'description' => $data['description'] ?? null, 'total_points' => $total, 'created_by' => $u->id]);
            }foreach ($data['criteria'] as $c) {
                $criterion = HomeworkRubricCriterion::create(['id' => (string) Str::uuid(), 'school_id' => $u->school_id, 'rubric_id' => $rubric->id, 'criterion' => $c['criterion'], 'description' => $c['description'] ?? null, 'maximum_points' => $c['maximum_points'] ?? null, 'display_order' => $c['display_order'] ?? 0]);
                foreach ($c['levels'] ?? [] as $l) {
                    HomeworkRubricLevel::create(['id' => (string) Str::uuid(), 'school_id' => $u->school_id, 'criterion_id' => $criterion->id] + $l);
                }
            }$this->assignments->audit($a, $rubric->wasRecentlyCreated ? 'rubric_created' : 'rubric_updated', $u->id);

            return $rubric->load('criteria.levels');
        });
    }

    public function get(User $u, string $id): HomeworkRubric
    {
        $a = $this->assignments->ownQuery($u)->whereKey($id)->firstOrFail();

        return HomeworkRubric::where('assignment_id', $a->id)->with('criteria.levels')->firstOrFail();
    }
}
