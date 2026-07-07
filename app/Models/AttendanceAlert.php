<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceAlert extends Model
{
    protected $table = 'attendance_alerts';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'school_id',

        'learner_id',

        'attendance_id',

        'parent_notified',

        'notification_method',

        'notified_at',

        'created_at',

    ];

    protected $casts = [

        'parent_notified' => 'boolean',

        'notified_at' => 'datetime',

        'created_at' => 'datetime',

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

    public function attendance()
    {
        return $this->belongsTo(

            LearnerAttendance::class,

            'attendance_id'

        );
    }
}
