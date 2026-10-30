<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $grading_snapshot
 */
class ReportCardLearningArea extends Model
{
    protected $table = 'report_card_learning_areas';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = ['grading_snapshot', 'id', 'report_card_id', 'learning_area_id', 'learning_area_result_id', 'is_absent', 'score', 'marks_obtained', 'maximum_marks', 'percentage', 'grading_system_id', 'grading_scale_id', 'grade_code', 'points', 'teacher_comment', 'is_deleted', 'deleted_at', 'deleted_by'];

    protected $casts = ['grading_snapshot' => 'array', 'is_absent' => 'boolean', 'score' => 'decimal:2', 'marks_obtained' => 'decimal:2', 'maximum_marks' => 'decimal:2', 'percentage' => 'decimal:2', 'points' => 'integer', 'is_deleted' => 'boolean', 'deleted_at' => 'datetime'];

    /**
     * @return BelongsTo<ReportCard, $this>
     */
    public function reportCard(): BelongsTo
    {
        return $this->belongsTo(ReportCard::class);
    }

    /**
     * @return BelongsTo<LearningArea, $this>
     */
    public function learningArea(): BelongsTo
    {
        return $this->belongsTo(LearningArea::class);
    }

    /**
     * @return BelongsTo<LearningAreaResult, $this>
     */
    public function learningAreaResult(): BelongsTo
    {
        return $this->belongsTo(LearningAreaResult::class);
    }

    /**
     * @return BelongsTo<GradingSystem, $this>
     */
    public function gradingSystem(): BelongsTo
    {
        return $this->belongsTo(GradingSystem::class);
    }

    /**
     * @return BelongsTo<GradingScale, $this>
     */
    public function gradingScale(): BelongsTo
    {
        return $this->belongsTo(GradingScale::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function scopeCurrent($q)
    {
        return $q->where('is_deleted', false);
    }
}
