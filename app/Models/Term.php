<?php

namespace App\Models;

class Term extends TenantModel
{
    protected $table = 'terms';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'school_id',

        'academic_year_id',

        'term_name',

        'start_date',

        'end_date',

        'active',

        'created_at',

    ];

    protected $casts = [

        'start_date' => 'date',

        'end_date' => 'date',

        'active' => 'boolean',

        'created_at' => 'datetime',

    ];

    public function school()
    {
        return $this->belongsTo(
            School::class,
            'school_id'
        );
    }

    public function academicYear()
    {
        return $this->belongsTo(
            AcademicYear::class,
            'academic_year_id'
        );
    }

    public function academicWeeks()
    {
        return $this->hasMany(
            AcademicWeek::class,
            'term_id'
        );
    }

    public function teacherAssignments()
    {
        return $this->hasMany(
            TeacherAssignment::class,
            'term_id'
        );
    }

    public function schemes()
    {
        return $this->hasMany(
            SchemeOfWork::class,
            'term_id'
        );
    }
}
