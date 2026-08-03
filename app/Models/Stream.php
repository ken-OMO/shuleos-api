<?php

namespace App\Models;

class Stream extends TenantModel
{
    protected $table = 'streams';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'school_id',

        'grade_id',

        'stream_name',

        'active',

        'created_at',

    ];

    protected $casts = [

        'active' => 'boolean',

        'created_at' => 'datetime',

    ];

    /**
     * School relationship
     */
    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }

    /**
     * Grade relationship
     */
    public function grade()
    {
        return $this->belongsTo(

            Grade::class,

            'grade_id'

        );
    }

    /**
     * Learners relationship
     */
    public function learners()
    {
        return $this->hasMany(

            Learner::class,

            'stream_id'

        );
    }
}
