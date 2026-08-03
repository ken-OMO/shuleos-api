<?php

namespace App\Models;

class LearnerPushDelivery extends TenantModel
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['school_id', 'user_id', 'learner_id', 'device_id', 'provider_message_id', 'failure_code'];

    protected $casts = ['queued_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime'];
}
