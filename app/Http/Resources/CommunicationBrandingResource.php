<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommunicationBrandingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['sender_display_name' => $this->sender_display_name, 'reply_to_email' => $this->reply_to_email, 'logo_reference' => $this->logo_reference, 'footer_text' => $this->footer_text, 'address' => $this->address, 'phone' => $this->phone, 'website' => $this->website, 'primary_color' => $this->primary_color, 'secondary_color' => $this->secondary_color];
    }
}
