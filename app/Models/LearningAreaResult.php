<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $grading_snapshot
 */
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
        'is_absent',
        'maximum_marks',
        'percentage',
        'grading_system_id',
        'grading_scale_id',
        'grading_snapshot',
        'processing_status',
        'processed_by',
        'processed_at',
        'is_deleted',
        'deleted_at',
        'deleted_by',
    ];

    protected $casts = [
        'grading_snapshot' => 'array',
        'marks_obtained' => 'decimal:2',
        'is_absent' => 'boolean',
        'maximum_marks' => 'decimal:2',
        'percentage' => 'decimal:2',
        'processed_at' => 'datetime',
        'is_deleted' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    /**
     * @return BelongsTo<Exam, $this>
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    /**
     * @return BelongsTo<Learner, $this>
     */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class, 'learner_id');
    }

    /**
     * @return BelongsTo<LearningArea, $this>
     */
    public function learningArea(): BelongsTo
    {
        return $this->belongsTo(LearningArea::class, 'learning_area_id');
    }

    /**
     * @return BelongsTo<GradingSystem, $this>
     */
    public function gradingSystem(): BelongsTo
    {
        return $this->belongsTo(GradingSystem::class, 'grading_system_id');
    }

    /**
     * @return BelongsTo<GradingScale, $this>
     */
    public function gradingScale(): BelongsTo
    {
        return $this->belongsTo(GradingScale::class, 'grading_scale_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_deleted', false);
    }
}
