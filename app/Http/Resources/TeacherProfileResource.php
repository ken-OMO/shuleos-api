<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class TeacherProfileResource extends TeacherPortalSafeResource
{
    public function toArray(Request $r): array
    {
        return parent::toArray($r);
    }
}
