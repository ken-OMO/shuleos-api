<?php

namespace App\Models;

class MeritList extends TenantModel
{
    protected $table = 'merit_lists';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'school_id',

        'exam_id',

        'learner_id',

        'grade_id',

        'stream_id',

        'total_score',

        'total_points',

        'stream_position',

        'grade_position',

        'school_position',

        'created_at',

    ];

    protected $casts = [

        'total_score' => 'decimal:2',

        'total_points' => 'decimal:2',

        'stream_position' => 'integer',

        'grade_position' => 'integer',

        'school_position' => 'integer',

        'created_at' => 'datetime',

    ];

    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }

    public function exam()
    {
        return $this->belongsTo(

            Exam::class,

            'exam_id'

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
}
