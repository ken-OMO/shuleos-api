<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuthenticationChallenge extends Model
{
    protected $table = 'authentication_challenges';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'school_id',
        'otp_hash',
        'purpose',
        'failed_attempts',
        'resend_count',
        'last_sent_at',
        'expires_at',
        'consumed_at',
        'challenge_nonce_hash',
        'ip_hash',
        'user_agent_hash',
    ];

    protected $hidden = [
        'otp_hash',
        'challenge_nonce_hash',
        'ip_hash',
        'user_agent_hash',
    ];

    protected $casts = [
        'failed_attempts' => 'integer',
        'resend_count' => 'integer',
        'last_sent_at' => 'datetime',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
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

    public function isExpired(): bool
    {
        return $this->expires_at?->isPast() ?? true;
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isUsable(): bool
    {
        return ! $this->isConsumed()
            && ! $this->isExpired();
    }
}
