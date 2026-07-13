<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HomeworkMarkResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        $released = $this->status === 'released' || in_array($r->user()?->role?->role_name, ['Teacher', 'HOD', 'Principal', 'Deputy Principal', 'School Admin'], true);

        return $released ? ['raw_score' => $this->raw_score, 'percentage' => $this->percentage, 'competency_level' => $this->competency_level, 'teacher_feedback' => $this->teacher_feedback, 'late_penalty_applied' => $this->late_penalty_applied, 'final_score' => $this->final_score, 'status' => $this->status, 'released_at' => $this->released_at] : [];
    }
}
