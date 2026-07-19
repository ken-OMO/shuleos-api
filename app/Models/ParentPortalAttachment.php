<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ParentPortalAttachment extends TenantModel
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['school_id', 'user_id', 'storage_id', 'source_hash', 'stored_hash', 'safe_filename'];

    protected $casts = ['attached_at' => 'datetime', 'archived_at' => 'datetime'];
}
