<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TimetableSubstitution extends TenantModel
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [

        'id', 'school_id',

        'timetable_entry_id',

        'absent_teacher_id',

        'substitute_teacher_id',

        'substitution_date',

        'reason',

        'approved_by',

        'status', 'approved_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason',

    ];

    protected $casts = ['substitution_date' => 'date', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime'];

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
