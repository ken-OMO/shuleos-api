<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class Timetable extends TenantModel
{
    use HasFactory;

    protected $fillable = [

        'school_id',

        'timetable_profile_id',

        'academic_year_id',

        'term_id',

        'timetable_name',

        'status',

        'active',

        'created_by',

    ];

    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }

    public function profile()
    {
        return $this->belongsTo(

            TimetableProfile::class,

            'timetable_profile_id'

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

    public function conflicts()
    {
        return $this->hasMany(

            TimetableConflict::class,

            'timetable_id'

        );
    }

    public function publications()
    {
        return $this->hasMany(

            TimetablePublication::class,

            'timetable_id'

        );
    }

    public function generationRuns()
    {
        return $this->hasMany(

            TimetableGenerationRun::class,

            'timetable_id'

        );
    }
}
