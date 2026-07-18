<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ParentDeviceResource extends ParentPortalArrayResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'platform' => $this->platform, 'app_version' => $this->app_version, 'device_name' => $this->device_name, 'push_enabled' => false, 'last_seen_at' => $this->last_seen_at, 'revoked_at' => $this->revoked_at, 'created_at' => $this->created_at];
    }
}
