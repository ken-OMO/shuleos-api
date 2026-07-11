<?php

namespace App\Models;

class AcademicYear extends TenantModel
{
    protected $table = 'academic_years';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'school_id',

        'year_name',

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

    /**
     * School relationship
     */
    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }

    /**
     * Terms relationship
     */
    public function terms()
    {
        return $this->hasMany(

            Term::class,

            'academic_year_id'

        );
    }

    /**
     * Academic Weeks relationship
     */
    public function academicWeeks()
    {
        return $this->hasMany(

            AcademicWeek::class,

            'academic_year_id'

        );
    }

    /**
     * Teacher Assignments relationship
     */
    public function teacherAssignments()
    {
        return $this->hasMany(

            TeacherAssignment::class,

            'academic_year_id'

        );
    }

    /**
     * Schemes Of Work relationship
     */
    public function schemesOfWork()
    {
        return $this->hasMany(

            SchemeOfWork::class,

            'academic_year_id'

        );
    }
}
