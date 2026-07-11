<?php

namespace App\Models;

class AssessmentType extends TenantModel
{
    protected $table = 'assessment_types';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'school_id',

        'assessment_type_name',

        'active',

        'created_at',
        'is_deleted', 'deleted_at', 'deleted_by',

    ];

    protected $casts = [

        'active' => 'boolean',

        'created_at' => 'datetime',
        'is_deleted' => 'boolean', 'deleted_at' => 'datetime',

    ];

    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }

    public function exams()
    {
        return $this->hasMany(

            Exam::class,

            'assessment_type_id'

        );
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_deleted', false);
    }
}
