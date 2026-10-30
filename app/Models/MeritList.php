<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $grading_snapshot
 */
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
        'overall_grading_system_id', 'overall_grading_scale_id', 'grading_snapshot',
        'stream_position', 'grade_position', 'school_position', 'ranking_method',
        'status', 'generated_by', 'generated_at', 'published_at', 'is_deleted',
        'deleted_at', 'deleted_by',
    ];

    protected $casts = [
        'grading_snapshot' => 'array',
        'total_score' => 'decimal:2', 'maximum_marks' => 'decimal:2',
        'average_percentage' => 'decimal:2', 'total_points' => 'integer',
        'stream_position' => 'integer', 'grade_position' => 'integer',
        'school_position' => 'integer', 'generated_at' => 'datetime',
        'published_at' => 'datetime', 'is_deleted' => 'boolean', 'deleted_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * @return BelongsTo<Exam, $this>
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /**
     * @return BelongsTo<Learner, $this>
     */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /**
     * @return BelongsTo<Grade, $this>
     */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    /**
     * @return BelongsTo<Stream, $this>
     */
    public function stream(): BelongsTo
    {
        return $this->belongsTo(Stream::class);
    }

    /**
     * @return BelongsTo<GradingSystem, $this>
     */
    public function overallGradingSystem(): BelongsTo
    {
        return $this->belongsTo(GradingSystem::class, 'overall_grading_system_id');
    }

    /**
     * @return BelongsTo<GradingScale, $this>
     */
    public function overallGradingScale(): BelongsTo
    {
        return $this->belongsTo(GradingScale::class, 'overall_grading_scale_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_deleted', false);
    }
}
