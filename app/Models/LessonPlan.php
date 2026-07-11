<?php

namespace App\Models;

class LessonPlan extends TenantModel
{
    protected $table = 'lesson_plans';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',
        'school_id',
        'teacher_assignment_id',
        'scheme_lesson_id',
        'lesson_date',
        'introduction',
        'lesson_development',
        'conclusion',
        'reflection',
        'status',
        'created_by',
        'created_at',
        'is_deleted',

        'deleted_at',
        'deleted_by',

    ];

    protected $casts = [

        'lesson_date' => 'date',
        'created_at' => 'datetime',
        'is_deleted' => 'boolean',

        'deleted_at' => 'datetime',

    ];

    public function scopeCurrent($query)
    {
        return $query->where('is_deleted', false);
    }

    public function assignment()
    {
        return $this->belongsTo(
            TeacherAssignment::class,
            'teacher_assignment_id'
        );
    }

    public function schemeLesson()
    {
        return $this->belongsTo(
            SchemeLesson::class,
            'scheme_lesson_id'
        );
    }

    public function notes()
    {
        return $this->hasMany(
            LessonNote::class,
            'lesson_plan_id'
        );
    }

    public function recordsOfWork()
    {
        return $this->hasMany(
            RecordOfWork::class,
            'lesson_plan_id'
        );
    }
}
