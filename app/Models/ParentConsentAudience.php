<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ParentConsentAudience extends TenantModel
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];
}
