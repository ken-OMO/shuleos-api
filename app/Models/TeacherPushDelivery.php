<?php

namespace App\Models;

class TeacherPushDelivery extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['school_id', 'user_id', 'device_id', 'provider_message_id', 'failure_code'];
}
