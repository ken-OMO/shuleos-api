<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class TimetableConflict extends TenantModel
{
    use HasFactory;

    protected $fillable = [

        'school_id',

        'timetable_id',

        'conflict_type',

        'severity',

        'description',

        'resolved',

        'resolved_at',

    ];

    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }

    public function timetable()
    {
        return $this->belongsTo(

            Timetable::class,

            'timetable_id'

        );
    }
}
