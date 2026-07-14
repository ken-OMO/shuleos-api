<?php

namespace App\Models;

class DisciplineCategory extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    public function scopeCurrent($q)
    {
        return $q->where('active', true)->where('is_deleted', false);
    }
}
