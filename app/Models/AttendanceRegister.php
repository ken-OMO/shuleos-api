<?php

namespace App\Models;

class AttendanceRegister extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['attendance_date' => 'date', 'opened_at' => 'datetime', 'finalized_at' => 'datetime', 'corrected_at' => 'datetime', 'is_deleted' => 'boolean'];

    public function records()
    {
        return $this->hasMany(LearnerAttendance::class, 'attendance_register_id');
    }

    public function session()
    {
        return $this->belongsTo(AttendanceSession::class, 'attendance_session_id');
    }

    public function teacherAssignment()
    {
        return $this->belongsTo(TeacherAssignment::class);
    }

    public function scopeCurrent($q)
    {
        return $q->where('is_deleted', false);
    }
}
