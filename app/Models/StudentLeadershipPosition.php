<?php

namespace App\Models;

class StudentLeadershipPosition extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['active' => 'boolean', 'is_deleted' => 'boolean'];

    public function scopeCurrent($q)
    {
        return $q->where('active', true)->where('is_deleted', false);
    }
}
