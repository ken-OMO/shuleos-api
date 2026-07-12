<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PathwayRecommendation extends Model
{
    protected $table = 'pathway_recommendations';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'learner_id', 'academic_year_id', 'recommendation_date', 'recommended_pathway', 'confidence_score', 'strengths', 'improvement_areas', 'generated_by', 'created_at'];

    protected $casts = ['recommendation_date' => 'date', 'confidence_score' => 'decimal:2', 'created_at' => 'datetime'];

    public function learner()
    {
        return $this->belongsTo(Learner::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
