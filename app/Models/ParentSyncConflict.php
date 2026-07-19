<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ParentSyncConflict extends TenantModel
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['school_id', 'user_id', 'safe_server_state'];

    protected $casts = ['safe_server_state' => 'array', 'resolved_at' => 'datetime'];
}
