<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'marks'
                => $this->marks,

            'exam'
                => $this->whenLoaded('exam'),

            'learner'
                => $this->whenLoaded('learner'),

            'learning_area'
                => $this->whenLoaded(

                    'learningArea'

                ),

            'paper'
                => $this->whenLoaded('paper'),

            'entered_by'
                => $this->whenLoaded(

                    'enteredBy'

                ),

            'created_at'
                => $this->created_at,

        ];
    }
}
