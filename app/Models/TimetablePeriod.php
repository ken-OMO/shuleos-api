<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimetablePeriod extends Model
{
    use HasFactory;

    protected $fillable = [

        'timetable_profile_id',

        'period_name',

        'period_order',

        'start_time',

        'end_time',

        'is_teaching_period',

        'is_break',

        'is_lunch',

        'is_assembly',

        'is_games',

        'is_club',

        'active',

    ];
    public function profile()
{
    return $this->belongsTo(

        TimetableProfile::class,

        'timetable_profile_id'

    );
}

public function teacherAvailabilities()
{
    return $this->hasMany(

        TeacherAvailability::class,

        'period_id'

    );
}
}
