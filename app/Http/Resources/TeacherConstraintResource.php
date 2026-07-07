<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherConstraintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,

            'teacher_id' => $this->teacher_id,

            'constraint_type' => $this->constraint_type,

            'constraint_value' => $this->constraint_value,

            'priority' => $this->priority,

            'active' => $this->active,

            'notes' => $this->notes,

            'created_at' => $this->created_at,

        ];
    }
}
