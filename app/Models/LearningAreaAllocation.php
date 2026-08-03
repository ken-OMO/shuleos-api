<?php

namespace App\Models;

class LearningAreaAllocation extends TenantModel
{
    protected $table = 'learning_area_allocations';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'school_id',

        'grade_id',

        'learning_area_id',

        'lessons_per_week',

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
     * Learning Area relationship
     */
    public function learningArea()
    {
        return $this->belongsTo(

            LearningArea::class,

            'learning_area_id'

        );
    }
}
