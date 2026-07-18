<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommunicationPreferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['email_enabled' => (bool) $this->email_enabled, 'sms_enabled' => (bool) $this->sms_enabled, 'in_app_enabled' => (bool) $this->in_app_enabled, 'digest_frequency' => $this->digest_frequency, 'quiet_hours_start' => $this->quiet_hours_start, 'quiet_hours_end' => $this->quiet_hours_end, 'timezone' => $this->timezone, 'language' => $this->language, 'emergency_override' => (bool) $this->emergency_override, 'marketing_opt_out' => (bool) $this->marketing_opt_out];
    }
}
