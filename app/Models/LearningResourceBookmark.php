<?php

namespace App\Models;

class LearningResourceBookmark extends TenantModel
{
    public $timestamps = false;

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';
}
