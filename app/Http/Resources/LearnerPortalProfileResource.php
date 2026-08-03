<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearnerPortalProfileResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        return ['id' => $this->id, 'name' => trim($this->first_name.' '.$this->middle_name.' '.$this->last_name), 'admission_no' => $this->admission_no, 'upi' => $this->upi, 'assessment_no' => $this->assessment_no, 'gender' => $this->gender, 'grade' => $this->whenLoaded('grade'), 'stream' => $this->whenLoaded('stream'), 'school' => $this->whenLoaded('school'), 'portal_activated_at' => $this->portal_activated_at];
    }
}
