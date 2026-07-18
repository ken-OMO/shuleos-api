<?php

namespace App\Models;

class LeadershipPortalDevice extends TenantModel
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['school_id', 'user_id', 'device_identifier_hash', 'push_token_encrypted'];

    protected $casts = [
        'push_enabled' => 'boolean',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
