<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PathwayRecommendationBuilder
{
    public static function create(
        object $learner,
        object $academicYear,
        array $attributes = []
    ): object {
        $id = (string) Str::uuid();

        $record = array_merge([
            'id' => $id,
            'learner_id' => $learner->id,
            'academic_year_id' => $academicYear->id,
            'recommendation_date' => now()->toDateString(),
            'recommended_pathway' => 'STEM',
            'confidence_score' => 90,
            'strengths' => null,
            'improvement_areas' => null,
            'generated_by' => 'SYSTEM',
        ], $attributes);

        DB::table('pathway_recommendations')->insert($record);

        return (object) $record;
    }
}
