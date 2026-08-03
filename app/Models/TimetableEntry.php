<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TimetableEntry extends TenantModel
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [

        'timetable_id',

        'school_id',

        'teacher_assignment_id',

        'timetable_day_id',

        'day_of_week',

        'period_id',

        'grade_id',

        'stream_id',

        'learning_area_id',

        'teacher_id',

        'room_id',

        'is_double_lesson',

        'remarks',

        'entry_status', 'created_by', 'updated_by', 'is_deleted', 'deleted_at', 'deleted_by',

        'lesson_group_id', 'lesson_sequence', 'lesson_span', 'is_locked', 'locked_by', 'locked_at', 'lock_reason', 'generation_run_id', 'generation_score',

    ];

    protected $casts = ['is_double_lesson' => 'boolean', 'is_deleted' => 'boolean', 'is_locked' => 'boolean', 'locked_at' => 'datetime'];

    public function timetable()
    {
        return $this->belongsTo(Timetable::class);
    }

    public function assignment()
    {
        return $this->belongsTo(TeacherAssignment::class, 'teacher_assignment_id');
    }

    public function period()
    {
        return $this->belongsTo(TimetablePeriod::class, 'period_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
