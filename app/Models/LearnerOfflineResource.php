<?php

namespace App\Models;

class LearnerOfflineResource extends TenantModel
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['school_id', 'learner_id'];

    protected $casts = ['available_offline_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function resource()
    {
        return $this->belongsTo(LearningResource::class);
    }
}
