<?php

namespace App\Services\Timetable;

use App\Models\Timetable;
use App\Models\TimetableEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class TimetableGenerationService
{
    public function __construct(private RoomAllocationService $rooms, private TimetableWorkloadService $workload, private TimetableValidationService $validation, private TimetableAuditService $audit) {}

    public function generate(User $user, string $id, array $options): array
    {
        Timetable::whereKey($id)->where('school_id', $user->school_id)->where('status', 'draft')->firstOrFail();
        $seed = (int) ($options['random_seed'] ?? 1);
        $maxIterations = min(max((int) ($options['max_iterations'] ?? 5000), 1), 20000);
        $runId = (string) Str::uuid();
        $run = ['id' => $runId, 'school_id' => $user->school_id, 'timetable_id' => $id, 'generated_by' => $user->id, 'generation_type' => $options['generation_type'] ?? 'full', 'status' => 'running', 'parameters' => json_encode(collect($options)->except('random_seed')->all()), 'random_seed' => $seed, 'started_at' => now(), 'created_at' => now()];
        DB::table('timetable_generation_runs')->insert($run);
        $this->audit->record($user, $id, 'generation_started', ['run_id' => $runId]);

        try {
            $transaction = DB::transaction(function () use ($user, $id, $options, $seed, $maxIterations, $runId) {
                $timetable = Timetable::whereKey($id)->where('school_id', $user->school_id)->where('status', 'draft')->lockForUpdate()->firstOrFail();
                if (! ($options['preserve_existing_entries'] ?? true)) {
                    TimetableEntry::where('timetable_id', $id)->where('school_id', $user->school_id)->where('is_locked', false)->where('entry_status', '!=', 'confirmed')->delete();
                }
                $days = DB::table('timetable_days')->where('school_id', $user->school_id)->where('timetable_profile_id', $timetable->timetable_profile_id)->where('active', true)->orderBy('day_order')->get();
                $periods = DB::table('timetable_periods')->where('timetable_profile_id', $timetable->timetable_profile_id)->where('active', true)->where('is_teaching_period', true)->orderBy('period_order')->get();
                $assignments = DB::table('teacher_assignments as a')->join('teachers as teacher', 'teacher.id', '=', 'a.teacher_id')->where('a.school_id', $user->school_id)->where('a.academic_year_id', $timetable->academic_year_id)->where('a.term_id', $timetable->term_id)->where('a.active', true)->where('a.is_deleted', false)->where('teacher.active', true)->where('teacher.is_deleted', false)->select('a.*')->get();
                if (! empty($options['focus_teacher_assignment_ids'])) {
                    $assignments = $assignments->whereIn('id', $options['focus_teacher_assignment_ids']);
                }
                $required = $assignments->sum('lessons_per_week');
                $scheduled = 0;
                $unscheduled = [];
                $iterations = 0;
                $scores = [];
                $placements = [];
                foreach ($assignments->sortByDesc('lessons_per_week') as $assignment) {
                    if (! $assignment->stream_id) {
                        $unscheduled[] = ['teacher_assignment_id' => $assignment->id, 'remaining' => (int) $assignment->lessons_per_week, 'reason' => 'Grade-wide assignment requires an explicit safe scheduling policy.'];

                        continue;
                    }
                    $existing = TimetableEntry::where('timetable_id', $id)->where('teacher_assignment_id', $assignment->id)->where('is_deleted', false)->count();
                    $remaining = max(0, (int) $assignment->lessons_per_week - $existing);
                    $doubleRequired = DB::table('timetable_constraints')->where('school_id', $user->school_id)->where('active', true)->where('constraint_type', 'requires_double_lesson')->where('scope_id', $assignment->id)->exists();
                    while ($remaining > 0 && $iterations < $maxIterations) {
                        $span = $doubleRequired && $remaining >= 2 ? 2 : 1;
                        $candidate = $this->bestCandidate($timetable, $assignment, $days, $periods, $span, $seed, $remaining);
                        $iterations++;
                        if (! $candidate) {
                            $unscheduled[] = ['teacher_assignment_id' => $assignment->id, 'remaining' => $remaining, 'reason' => 'No hard-valid slot available.'];
                            break;
                        }
                        $group = $span === 2 ? (string) Str::uuid() : null;
                        foreach ($candidate['periods'] as $sequence => $period) {
                            TimetableEntry::create(['school_id' => $user->school_id, 'timetable_id' => $id, 'teacher_assignment_id' => $assignment->id, 'timetable_day_id' => $candidate['day']->id, 'day_of_week' => $candidate['day']->day_of_week, 'period_id' => $period->id, 'grade_id' => $assignment->grade_id, 'stream_id' => $assignment->stream_id, 'learning_area_id' => $assignment->learning_area_id, 'teacher_id' => $assignment->teacher_id, 'room_id' => $candidate['room_id'], 'is_double_lesson' => $span === 2, 'lesson_group_id' => $group, 'lesson_sequence' => $span === 2 ? $sequence + 1 : null, 'lesson_span' => $span, 'entry_status' => 'draft', 'created_by' => $user->id, 'updated_by' => $user->id, 'generation_run_id' => $runId, 'generation_score' => $candidate['score']]);
                        }
                        $scores[] = $candidate['score'];
                        if (count($placements) < 100) {
                            $placements[] = ['teacher_assignment_id' => $assignment->id, 'day_of_week' => $candidate['day']->day_of_week, 'period_ids' => $candidate['periods']->pluck('id')->all(), 'room_id' => $candidate['room_id'], 'score' => $candidate['score'], 'score_factors' => $candidate['factors'], 'violated_soft_constraints' => $candidate['violated_soft_constraints']];
                        }
                        $scheduled += $span;
                        $remaining -= $span;
                    }
                }

                $result = ['required_lessons' => (int) $required, 'scheduled_lessons' => $scheduled, 'unscheduled_lessons' => collect($unscheduled)->sum('remaining'), 'unscheduled' => $unscheduled, 'placements' => $placements, 'iterations' => $iterations, 'average_score' => $scores ? round(array_sum($scores) / count($scores), 2) : null];

                return ['result' => $result, 'validation' => $this->validation->validate($user, $id)];
            });
            $result = $transaction['result'];
            $validation = $transaction['validation'];
            $status = $result['unscheduled_lessons'] || $validation['summary']['warnings'] ? 'completed_with_warnings' : 'completed';
            DB::table('timetable_generation_runs')->where('id', $runId)->where('school_id', $user->school_id)->update(['status' => $status, 'required_lessons' => $result['required_lessons'], 'scheduled_lessons' => $result['scheduled_lessons'], 'unscheduled_lessons' => $result['unscheduled_lessons'], 'hard_conflicts' => $validation['summary']['blocking_conflicts'], 'soft_warnings' => $validation['summary']['warnings'], 'score' => $result['average_score'], 'diagnostics' => json_encode(['unscheduled' => array_slice($result['unscheduled'], 0, 100), 'placements' => $result['placements'], 'iterations' => $result['iterations']]), 'completed_at' => now(), 'total_entries' => $result['scheduled_lessons'], 'total_conflicts' => $validation['summary']['blocking_conflicts'] + $validation['summary']['warnings']]);
            $this->audit->record($user, $id, 'generation_completed', ['run_id' => $runId, 'new' => collect($result)->except('unscheduled')->all()]);

            return ['run_id' => $runId, 'status' => $status, 'result' => $result, 'validation' => $validation];
        } catch (Throwable $exception) {
            DB::table('timetable_generation_runs')->where('id', $runId)->where('school_id', $user->school_id)->update(['status' => 'failed', 'failed_reason' => Str::limit($exception->getMessage(), 1000), 'completed_at' => now()]);
            $this->audit->record($user, $id, 'generation_failed', ['run_id' => $runId], Str::limit($exception->getMessage(), 1000));
            throw $exception;
        }
    }

    private function bestCandidate(Timetable $timetable, object $assignment, $days, $periods, int $span, int $seed, int $unit): ?array
    {
        $candidates = [];
        foreach ($days as $day) {
            foreach ($periods as $index => $period) {
                $pair = collect([$period]);
                if ($span === 2) {
                    $next = $periods->get($index + 1);
                    if (! $next || (int) $next->period_order !== (int) $period->period_order + 1) {
                        continue;
                    }
                    $pair->push($next);
                }
                if (! $this->hardValid($timetable, $assignment, $day, $pair)) {
                    continue;
                }
                $room = $this->rooms->compatible($timetable->school_id, $assignment->learning_area_id, $timetable->id, $day->day_of_week, $period->id)->first(function ($room) use ($timetable, $day, $pair) {
                    return ! TimetableEntry::where('timetable_id', $timetable->id)->where('day_of_week', $day->day_of_week)->whereIn('period_id', $pair->pluck('id'))->where('room_id', $room->id)->where('is_deleted', false)->exists();
                });
                $roomRequired = DB::table('timetable_constraints')->where('school_id', $timetable->school_id)->where('active', true)->where('is_hard', true)->whereIn('constraint_type', ['practical_room_required', 'room_type_required'])->where(fn ($query) => $query->where('scope_id', $assignment->id)->orWhere('scope_id', $assignment->learning_area_id))->exists();
                if ($roomRequired && ! $room) {
                    continue;
                }
                $score = $this->workload->score($timetable->school_id, $timetable->id, $assignment, $day->day_of_week, $period);
                $tie = sprintf('%u', crc32($seed.'|'.$assignment->id.'|'.$unit.'|'.$day->id.'|'.$period->id));
                $candidates[] = ['day' => $day, 'periods' => $pair, 'room_id' => $room?->id, 'score' => $score['score'], 'factors' => $score['factors'], 'violated_soft_constraints' => $score['violated_soft_constraints'], 'tie' => $tie];
            }
        }

        return collect($candidates)->sortBy([['score', 'desc'], ['tie', 'asc']])->first();
    }

    private function hardValid(Timetable $timetable, object $assignment, object $day, $periods): bool
    {
        $daily = TimetableEntry::where('timetable_id', $timetable->id)->where('teacher_id', $assignment->teacher_id)->where('day_of_week', $day->day_of_week)->where('is_deleted', false)->count();
        $limit = DB::table('timetable_constraints')->where('school_id', $timetable->school_id)->where('active', true)->where('is_hard', true)->where('constraint_type', 'maximum_daily_lessons')->where(fn ($query) => $query->where('scope_id', $assignment->teacher_id)->orWhere('scope_id', $assignment->id))->get()->map(fn ($constraint) => (int) (json_decode($constraint->configuration ?: '{}', true)['maximum'] ?? 0))->filter()->min();
        if ($limit && $daily + $periods->count() > $limit) {
            return false;
        }
        foreach ($periods as $period) {
            $slot = TimetableEntry::where('timetable_id', $timetable->id)->where('day_of_week', $day->day_of_week)->where('period_id', $period->id)->where('is_deleted', false);
            if ((clone $slot)->where('teacher_id', $assignment->teacher_id)->exists() || (clone $slot)->where('stream_id', $assignment->stream_id)->exists()) {
                return false;
            }
            if (Schema::hasTable('teacher_availabilities') && DB::table('teacher_availabilities')->where('school_id', $timetable->school_id)->where('teacher_id', $assignment->teacher_id)->where('day_of_week', $day->day_of_week)->where('period_id', $period->id)->where('is_available', false)->exists()) {
                return false;
            }
        }

        return true;
    }
}
