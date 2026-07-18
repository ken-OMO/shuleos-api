<?php

namespace App\Models;

class LeadershipPortalAuditLog extends TenantModel
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['safe_metadata' => 'array', 'created_at' => 'datetime'];
}
