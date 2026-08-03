<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParentProfileResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone_masked' => $this->maskPhone($this->phone),
            'email_masked' => $this->maskEmail($this->email),
        ];
    }

    private function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).str_repeat('*', max(2, mb_strlen($local) - 1)).'@'.$domain;
    }

    private function maskPhone(?string $phone): ?string
    {
        return $phone ? str_repeat('*', max(0, mb_strlen($phone) - 4)).mb_substr($phone, -4) : null;
    }
}
