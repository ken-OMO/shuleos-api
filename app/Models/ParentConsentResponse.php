<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ParentConsentResponse extends TenantModel
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['school_id', 'parent_user_id'];

    protected $casts = ['responded_at' => 'datetime'];
}
