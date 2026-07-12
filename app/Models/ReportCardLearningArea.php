<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportCardLearningArea extends Model
{
    protected $table = 'report_card_learning_areas';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = ['id', 'report_card_id', 'learning_area_id', 'learning_area_result_id', 'score', 'marks_obtained', 'maximum_marks', 'percentage', 'grading_system_id', 'grading_scale_id', 'grade_code', 'points', 'teacher_comment', 'is_deleted', 'deleted_at', 'deleted_by'];

    protected $casts = ['score' => 'decimal:2', 'marks_obtained' => 'decimal:2', 'maximum_marks' => 'decimal:2', 'percentage' => 'decimal:2', 'points' => 'integer', 'is_deleted' => 'boolean', 'deleted_at' => 'datetime'];

    public function reportCard()
    {
        return $this->belongsTo(ReportCard::class);
    }

    public function learningArea()
    {
        return $this->belongsTo(LearningArea::class);
    }

    public function learningAreaResult()
    {
        return $this->belongsTo(LearningAreaResult::class);
    }

    public function gradingSystem()
    {
        return $this->belongsTo(GradingSystem::class);
    }

    public function gradingScale()
    {
        return $this->belongsTo(GradingScale::class);
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function scopeCurrent($q)
    {
        return $q->where('is_deleted', false);
    }
}
