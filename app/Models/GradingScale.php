<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradingScale extends Model
{
    protected $table = 'grading_scales';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'grading_system_id',
        'grade_code',
        'grade_description',
        'min_score',
        'max_score',
        'points',
        'sort_order',
        'created_at',
    ];

    protected $casts = [
        'min_score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'points' => 'integer',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
    ];

    public function gradingSystem()
    {
        return $this->belongsTo(GradingSystem::class, 'grading_system_id');
    }

    public function learningAreaResults()
    {
        return $this->hasMany(LearningAreaResult::class, 'grading_scale_id');
    }

    public function scopeMatchingPercentage($query, float $percentage)
    {
        return $query
            ->whereNotNull('min_score')
            ->whereNotNull('max_score')
            ->where('min_score', '<=', $percentage)
            ->where('max_score', '>=', $percentage);
    }
}
