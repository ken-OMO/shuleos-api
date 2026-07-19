<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ParentConsent extends TenantModel
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['school_id'];

    protected $casts = ['reason_required_on_decline' => 'boolean', 'published_at' => 'datetime', 'expires_at' => 'datetime', 'withdrawn_at' => 'datetime'];

    public function audiences()
    {
        return $this->hasMany(ParentConsentAudience::class, 'consent_id');
    }

    public function responses()
    {
        return $this->hasMany(ParentConsentResponse::class, 'consent_id');
    }
}
