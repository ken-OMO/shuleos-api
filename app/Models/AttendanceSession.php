<?php

namespace App\Models;

class AttendanceSession extends TenantModel
{
    protected $table = 'attendance_sessions';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'school_id',

        'session_name',

        'session_order',

        'active',

        'created_at',

    ];

    protected $casts = [

        'active' => 'boolean',

        'created_at' => 'datetime',

    ];

    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }

    public function attendanceRecords()
    {
        return $this->hasMany(

            LearnerAttendance::class,

            'attendance_session_id'

        );
    }
}
