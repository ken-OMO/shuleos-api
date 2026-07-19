<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ParentAppointment extends TenantModel
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['school_id', 'parent_user_id', 'resolved_staff_user_id', 'meeting_link_encrypted'];

    protected $casts = ['preferred_from' => 'datetime', 'preferred_to' => 'datetime', 'proposed_at' => 'datetime', 'confirmed_at' => 'datetime'];

    public function learner()
    {
        return $this->belongsTo(Learner::class);
    }
}
