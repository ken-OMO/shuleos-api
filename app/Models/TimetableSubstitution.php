<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimetableSubstitution extends Model
{
    use HasFactory;

    protected $fillable = [

        'school_id',

        'timetable_entry_id',

        'absent_teacher_id',

        'substitute_teacher_id',

        'substitution_date',

        'reason',

        'approved_by',

    ];
    public function school()
{
    return $this->belongsTo(

        School::class,

        'school_id'

    );
}

public function absentTeacher()
{
    return $this->belongsTo(

        Teacher::class,

        'absent_teacher_id'

    );
}

public function substituteTeacher()
{
    return $this->belongsTo(

        Teacher::class,

        'substitute_teacher_id'

    );
}
}
