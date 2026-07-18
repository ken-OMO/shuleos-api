<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ParentReportCardResource extends ParentPortalArrayResource
{
    public function toArray(Request $request): array
    {
        if (is_array($this->resource) && isset($this->resource['report_card'])) {
            $card = $this->resource['report_card'];
            $access = $this->resource['access'];

            if (! $access['can_view']) {
                return [
                    'id' => $card->id,
                    'access' => [
                        'can_view' => false,
                        'can_download' => false,
                        'lock_reason' => $access['restriction_message'] ?: 'Report card access is restricted.',
                    ],
                ];
            }

            return [
                'id' => $card->id,
                'exam' => $card->exam ? ['id' => $card->exam->id, 'name' => $card->exam->exam_name] : null,
                'term' => $card->term ? ['id' => $card->term->id, 'name' => $card->term->term_name] : null,
                'overall_score' => $card->overall_score,
                'maximum_marks' => $card->maximum_marks,
                'average_percentage' => $card->average_percentage,
                'grade' => $card->overallGradingScale?->grade_code,
                'grade_description' => $card->overallGradingScale?->grade_description,
                'points' => $card->total_points,
                'published_at' => $card->published_at,
                'access' => ['can_view' => (bool) $access['can_view'], 'can_download' => (bool) $access['can_download'], 'lock_reason' => $access['can_view'] && $access['can_download'] ? null : ($access['restriction_message'] ?: 'Report card access is restricted.')],
            ];
        }

        return parent::toArray($request);
    }
}
