<?php

namespace App\Models;

class ParentPortalDevice extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['school_id', 'user_id', 'device_identifier_hash', 'push_token_encrypted'];

    protected $casts = [
        'push_enabled' => 'boolean',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
