<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurriculumCoverage extends Model
{
    protected $table = 'curriculum_coverage';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',
        'school_id',
        'teacher_assignment_id',
        'scheme_id',
        'scheme_lesson_id',
        'record_of_work_id',
        'date_completed',
        'strand',
        'sub_strand',
        'week_number',
        'completed',
        'created_at',
        'is_deleted',
        'deleted_at',
        'deleted_by',

    ];

    protected $casts = [

        'date_completed' => 'date',
        'completed' => 'boolean',
        'created_at' => 'datetime',
        'is_deleted' => 'boolean',
        'deleted_at' => 'datetime',

    ];

    public function recordOfWork()
    {
        return $this->belongsTo(
            RecordOfWork::class,
            'record_of_work_id'
        );
    }

    public function scheme()
    {
        return $this->belongsTo(
            SchemeOfWork::class,
            'scheme_id'
        );
    }

    public function schemeLesson()
    {
        return $this->belongsTo(
            SchemeLesson::class,
            'scheme_lesson_id'
        );
    }

    public function teacherAssignment()
    {
        return $this->belongsTo(
            TeacherAssignment::class,
            'teacher_assignment_id'
        );
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_deleted', false);
    }
}
