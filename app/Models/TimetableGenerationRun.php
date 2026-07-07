<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimetableGenerationRun extends Model
{
    use HasFactory;

    protected $fillable = [

        'school_id',

        'timetable_id',

        'generated_by',

        'generation_type',

        'status',

        'total_entries',

        'total_conflicts',

        'started_at',

        'completed_at',

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
