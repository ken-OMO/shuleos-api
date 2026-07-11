<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TimetableProfile extends TenantModel
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'timetable_profiles';

    protected $fillable = [

        'school_id',

        'profile_name',

        'education_level_id',

        'periods_per_day',

        'periods_per_week',

        'lesson_duration_minutes',

        'allow_double_lessons',

        'use_cbc_template',

        'active',

        'is_default',

    ];

    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }

    public function timetables()
    {
        return $this->hasMany(

            Timetable::class,

            'timetable_profile_id'

        );
    }

    public function periods()
    {
        return $this->hasMany(

            TimetablePeriod::class,

            'timetable_profile_id'

        );
    }
}
