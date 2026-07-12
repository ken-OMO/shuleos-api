<?php

namespace App\Models;

class GradingSystem extends TenantModel
{
    protected $table = 'grading_systems';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'school_id',
        'grading_name',
        'education_level_id',
        'uses_points',
        'uses_marks',
        'active',
        'created_at',
    ];

    protected $casts = [
        'uses_points' => 'boolean',
        'uses_marks' => 'boolean',
        'active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function school()
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function educationLevel()
    {
        return $this->belongsTo(EducationLevel::class, 'education_level_id');
    }

    public function scales()
    {
        return $this->hasMany(GradingScale::class, 'grading_system_id')
            ->orderBy('sort_order');
    }

    public function learningAreaResults()
    {
        return $this->hasMany(LearningAreaResult::class, 'grading_system_id');
    }
}
