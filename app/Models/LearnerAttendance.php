<?php

namespace App\Models;

class LearnerAttendance extends TenantModel
{
    protected $table = 'learner_attendance';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [

        'id',

        'school_id',

        'learner_id',

        'grade_id',

        'stream_id',

        'attendance_session_id',

        'attendance_status_id',

        'attendance_date',

        'remarks',

        'marked_by',

    ];

    protected $casts = [

        'attendance_date' => 'date',

        'created_at' => 'datetime',

        'updated_at' => 'datetime',

    ];

    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }

    public function learner()
    {
        return $this->belongsTo(

            Learner::class,

            'learner_id'

        );
    }

    public function grade()
    {
        return $this->belongsTo(

            Grade::class,

            'grade_id'

        );
    }

    public function stream()
    {
        return $this->belongsTo(

            Stream::class,

            'stream_id'

        );
    }

    public function attendanceSession()
    {
        return $this->belongsTo(

            AttendanceSession::class,

            'attendance_session_id'

        );
    }

    public function attendanceStatus()
    {
        return $this->belongsTo(

            AttendanceStatus::class,

            'attendance_status_id'

        );
    }

    public function markedBy()
    {
        return $this->belongsTo(

            User::class,

            'marked_by'

        );
    }

    public function alerts()
    {
        return $this->hasMany(

            AttendanceAlert::class,

            'attendance_id'

        );
    }
}
