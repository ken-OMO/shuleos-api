<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ParentPushDelivery extends TenantModel
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['school_id', 'user_id', 'device_id', 'provider_message_id'];

    protected $casts = ['queued_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime'];
}
