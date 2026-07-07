<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceAlertResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array
    {
        return [

            'id' => $this->id,

            'parent_notified'
                => $this->parent_notified,

            'notification_method'
                => $this->notification_method,

            'notified_at'
                => $this->notified_at,

            'school'
                => $this->whenLoaded(

                    'school'

                ),

            'learner'
                => $this->whenLoaded(

                    'learner'

                ),

            'attendance'
                => $this->whenLoaded(

                    'attendance'

                ),

            'created_at'
                => $this->created_at,

        ];
    }
}
