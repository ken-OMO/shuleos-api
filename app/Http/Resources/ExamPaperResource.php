<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamPaperResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'paper_name'
                => $this->paper_name,

            'paper_number'
                => $this->paper_number,

            'max_marks'
                => $this->max_marks,

            'exam_learning_area'
                => $this->whenLoaded(

                    'examLearningArea'

                ),

            'results'
                => $this->whenLoaded(

                    'results'

                ),

            'created_at'
                => $this->created_at,

        ];
    }
}
