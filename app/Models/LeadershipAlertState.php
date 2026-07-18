<?php

namespace App\Models;

class LeadershipAlertState extends TenantModel
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['acted_at' => 'datetime'];
}
