<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuthenticationSession extends Model
{
    protected $table = 'authentication_sessions';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'school_id',
        'token_jti_hash',
        'device_fingerprint_hash',
        'user_agent_summary',
        'ip_hash',
        'authenticated_at',
        'last_seen_at',
        'revoked_at',
        'revocation_reason',
    ];

    protected $hidden = [
        'token_jti_hash',
        'device_fingerprint_hash',
        'ip_hash',
    ];

    protected $casts = [
        'authenticated_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(
            User::class,
            'user_id'
        );
    }

    public function school()
    {
        return $this->belongsTo(
            School::class,
            'school_id'
        );
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked();
    }
}
