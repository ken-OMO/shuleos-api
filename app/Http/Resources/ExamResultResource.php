<?php

namespace App\Http\Resources;

use App\Models\ExamResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExamResult
 */
class ExamResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,
            'exam_id' => $this->exam_id, 'learner_id' => $this->learner_id, 'learning_area_id' => $this->learning_area_id, 'paper_id' => $this->paper_id,

            'marks' => $this->marks, 'is_absent' => $this->is_absent,

            'exam' => $this->whenLoaded('exam'),

            'learner' => $this->whenLoaded('learner'),

            'learning_area' => $this->whenLoaded(

                'learningArea'

            ),

            'paper' => $this->whenLoaded('paper'),

            'entered_by' => $this->whenLoaded(

                'enteredBy'

            ),

            'created_at' => $this->created_at,

        ];
    }
}
