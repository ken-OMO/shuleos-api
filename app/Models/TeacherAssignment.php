<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherAssignment extends Model
{
    protected $table = 'teacher_assignments';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'school_id',

        'teacher_id',

        'learning_area_id',

        'grade_id',

        'stream_id',

        'academic_year_id',

        'term_id',

        'is_class_teacher',

        'lessons_per_week',

        'active',

        'is_deleted',

        'deleted_at',

        'deleted_by',

        'created_at',

    ];

    protected $casts = [

        'is_class_teacher' => 'boolean',

        'active' => 'boolean',

        'is_deleted' => 'boolean',

        'created_at' => 'datetime',

        'deleted_at' => 'datetime',

    ];

    public function school()
    {
        return $this->belongsTo(
            School::class,
            'school_id'
        );
    }

    public function teacher()
    {
        return $this->belongsTo(
            Teacher::class,
            'teacher_id'
        );
    }

    public function learningArea()
    {
        return $this->belongsTo(
            LearningArea::class,
            'learning_area_id'
        );
    }

    public function grade()
    {
        return $this->belongsTo(
            Grade::class,
            'grade_id'
        );
    }

    public function stream()
    {
        return $this->belongsTo(
            Stream::class,
            'stream_id'
        );
    }

    public function academicYear()
    {
        return $this->belongsTo(
            AcademicYear::class,
            'academic_year_id'
        );
    }

    public function term()
    {
        return $this->belongsTo(
            Term::class,
            'term_id'
        );
    }

    public function lessonPlans()
    {
        return $this->hasMany(
            LessonPlan::class,
            'teacher_assignment_id'
        );
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_deleted', false);
    }
}
