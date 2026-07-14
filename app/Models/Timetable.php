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

        'version', 'approved_by', 'approved_at', 'published_by', 'published_at', 'archived_at', 'validation_summary', 'validated_at', 'is_deleted', 'deleted_at', 'deleted_by',

    ];

    protected $casts = ['validation_summary' => 'array', 'approved_at' => 'datetime', 'published_at' => 'datetime', 'archived_at' => 'datetime', 'validated_at' => 'datetime', 'is_deleted' => 'boolean'];

    public function entries()
    {
        return $this->hasMany(TimetableEntry::class);
    }

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
