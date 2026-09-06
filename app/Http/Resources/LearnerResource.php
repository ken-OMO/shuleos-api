<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearnerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'admission_no' => $this->admission_no,

            'upi' => $this->upi,

            'assessment_no' => $this->assessment_no,

            'first_name' => $this->first_name,

            'middle_name' => $this->middle_name,

            'last_name' => $this->last_name,

            'gender' => $this->gender,

            'date_of_birth' => $this->date_of_birth,

            'admission_date' => $this->admission_date,

            'active' => $this->active,

            'lifecycle_status' => $this->lifecycle_status,

            'mode_of_study' => $this->mode_of_study,

            'school' => $this->whenLoaded(
                'school'
            ),

            'grade' => $this->whenLoaded(
                'grade'
            ),

            'stream' => $this->whenLoaded(
                'stream'
            ),

            'created_at' => $this->created_at,

            'updated_at' => $this->updated_at,

        ];
    }
}
