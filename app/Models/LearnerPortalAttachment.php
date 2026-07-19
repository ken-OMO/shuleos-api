<?php

namespace App\Models;

class LearnerPortalAttachment extends TenantModel
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['school_id', 'user_id', 'learner_id', 'storage_id', 'source_hash', 'stored_hash'];

    protected $casts = ['attached_at' => 'datetime', 'archived_at' => 'datetime'];
}
