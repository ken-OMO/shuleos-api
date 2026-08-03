<?php

namespace App\Models;

class MeritList extends TenantModel
{
    protected $table = 'merit_lists';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'id', 'school_id', 'exam_id', 'learner_id', 'grade_id', 'stream_id',
        'total_score', 'maximum_marks', 'average_percentage', 'total_points',
        'overall_grading_system_id', 'overall_grading_scale_id',
        'stream_position', 'grade_position', 'school_position', 'ranking_method',
        'status', 'generated_by', 'generated_at', 'published_at', 'is_deleted',
        'deleted_at', 'deleted_by',
    ];

    protected $casts = [
        'total_score' => 'decimal:2', 'maximum_marks' => 'decimal:2',
        'average_percentage' => 'decimal:2', 'total_points' => 'integer',
        'stream_position' => 'integer', 'grade_position' => 'integer',
        'school_position' => 'integer', 'generated_at' => 'datetime',
        'published_at' => 'datetime', 'is_deleted' => 'boolean', 'deleted_at' => 'datetime',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function learner()
    {
        return $this->belongsTo(Learner::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function stream()
    {
        return $this->belongsTo(Stream::class);
    }

    public function overallGradingSystem()
    {
        return $this->belongsTo(GradingSystem::class, 'overall_grading_system_id');
    }

    public function overallGradingScale()
    {
        return $this->belongsTo(GradingScale::class, 'overall_grading_scale_id');
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_deleted', false);
    }
}
