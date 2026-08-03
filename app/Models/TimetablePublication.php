<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class TimetablePublication extends TenantModel
{
    use HasFactory;

    protected $fillable = [

        'school_id',

        'timetable_id',

        'publication_status',

        'published_by',

        'published_at',

        'notes',

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
