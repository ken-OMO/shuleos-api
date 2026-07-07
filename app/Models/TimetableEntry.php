<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimetableEntry extends Model
{
    use HasFactory;

    protected $fillable = [

        'timetable_id',

        'day_of_week',

        'period_id',

        'grade_id',

        'stream_id',

        'learning_area_id',

        'teacher_id',

        'room_id',

        'is_double_lesson',

        'remarks',

    ];
}
