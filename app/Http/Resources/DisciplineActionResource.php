<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DisciplineActionResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        return ['id' => $this->id, 'action_type' => $this->action_type, 'action_date' => $this->action_date, 'status' => $this->status, 'due_at' => $this->due_at, 'completed_at' => $this->completed_at, 'follow_up_required' => (bool) $this->follow_up_required];
    }
}
