<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeritListResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array {
        return [

            'id' => $this->id,

            'total_score' => $this->total_score,

            'total_points' => $this->total_points,

            'stream_position' => $this->stream_position,

            'grade_position' => $this->grade_position,

            'school_position' => $this->school_position,

            'school' => $this->whenLoaded(

                'school'

            ),

            'exam' => $this->whenLoaded(

                'exam'

            ),

            'learner' => $this->whenLoaded(

                'learner'

            ),

            'grade' => $this->whenLoaded(

                'grade'

            ),

            'stream' => $this->whenLoaded(

                'stream'

            ),

            'created_at' => $this->created_at,

        ];
    }
}
