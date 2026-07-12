<?php

namespace App\Models;

class LearningAreaResult extends TenantModel
{
    protected $table = 'learning_area_results';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'school_id',
        'exam_id',
        'learner_id',
        'learning_area_id',
        'marks_obtained',
        'maximum_marks',
        'percentage',
        'grading_system_id',
        'grading_scale_id',
        'processing_status',
        'processed_by',
        'processed_at',
        'is_deleted',
        'deleted_at',
        'deleted_by',
    ];

    protected $casts = [
        'marks_obtained' => 'decimal:2',
        'maximum_marks' => 'decimal:2',
        'percentage' => 'decimal:2',
        'processed_at' => 'datetime',
        'is_deleted' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function school()
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    public function learner()
    {
        return $this->belongsTo(Learner::class, 'learner_id');
    }

    public function learningArea()
    {
        return $this->belongsTo(LearningArea::class, 'learning_area_id');
    }

    public function gradingSystem()
    {
        return $this->belongsTo(GradingSystem::class, 'grading_system_id');
    }

    public function gradingScale()
    {
        return $this->belongsTo(GradingScale::class, 'grading_scale_id');
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_deleted', false);
    }
}
