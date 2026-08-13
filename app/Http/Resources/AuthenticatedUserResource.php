<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthenticatedUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],
            'first_name' => $this->resource['first_name'],
            'last_name' => $this->resource['last_name'],
            'username' => $this->resource['username'],
            'email' => $this->resource['email'],
            'status' => $this->resource['status'],
            'scope' => $this->resource['scope'],
            'school_id' => $this->resource['school_id'],
            'school' => $this->resource['school'],
            'roles' => $this->resource['roles'],
            'permissions' => $this->resource['permissions'],
            'password_reset_required' => $this->resource['password_reset_required'],
            'account' => $this->resource['account'],
        ];
    }
}
