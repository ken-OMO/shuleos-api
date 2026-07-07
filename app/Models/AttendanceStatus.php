<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceStatus extends Model
{
    protected $table = 'attendance_statuses';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'status_name',

        'status_code',

        'active',

        'created_at',

    ];

    protected $casts = [

        'active' => 'boolean',

        'created_at' => 'datetime',

    ];

    public function attendanceRecords()
    {
        return $this->hasMany(

            LearnerAttendance::class,

            'attendance_status_id'

        );
    }
}
