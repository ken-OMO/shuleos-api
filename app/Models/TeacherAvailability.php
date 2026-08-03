<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class TeacherAvailability extends TenantModel
{
    use HasFactory;

    protected $fillable = [

        'school_id',

        'teacher_id',

        'day_of_week',

        'period_id',

        'is_available',

        'remarks',

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

    public function period()
    {
        return $this->belongsTo(

            TimetablePeriod::class,

            'period_id'

        );
    }
}
