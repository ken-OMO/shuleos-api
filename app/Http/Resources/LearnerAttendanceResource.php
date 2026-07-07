<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearnerAttendanceResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array
    {
        return [

            'id' => $this->id,

            'attendance_date'
                => $this->attendance_date,

            'remarks'
                => $this->remarks,

            'school'
                => $this->whenLoaded(

                    'school'

                ),

            'learner'
                => $this->whenLoaded(

                    'learner'

                ),

            'grade'
                => $this->whenLoaded(

                    'grade'

                ),

            'stream'
                => $this->whenLoaded(

                    'stream'

                ),

            'attendance_session'
                => $this->whenLoaded(

                    'attendanceSession'

                ),

            'attendance_status'
                => $this->whenLoaded(

                    'attendanceStatus'

                ),

            'marked_by'
                => $this->whenLoaded(

                    'markedBy'

                ),

            'created_at'
                => $this->created_at,

            'updated_at'
                => $this->updated_at,

        ];
    }
}
