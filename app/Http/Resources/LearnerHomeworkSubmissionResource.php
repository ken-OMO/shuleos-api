<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class LearnerHomeworkSubmissionResource extends LearnerPortalSafeResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        if (isset($data['mark']) && (($data['mark']['status'] ?? null) !== 'released')) {
            unset($data['mark']);
        }

        return $data;
    }
}
