<?php

namespace App\Models;

class AttendanceRiskFlag extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'generated_at' => 'datetime', 'metadata' => 'array'];
}
