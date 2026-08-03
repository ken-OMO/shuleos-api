<?php

namespace App\Models;

class LearningResourceRating extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';
}
