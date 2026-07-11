<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamLearningAreaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'learning_area_id' => $this->learning_area_id,

            'number_of_papers'
                => $this->number_of_papers,

            'total_marks'
                => $this->total_marks,

            'exam'
                => $this->whenLoaded('exam'),

            'learning_area'
                => $this->whenLoaded('learningArea'),

            'papers'
                => $this->whenLoaded('papers'),

            'created_at'
                => $this->created_at,

        ];
    }
}
