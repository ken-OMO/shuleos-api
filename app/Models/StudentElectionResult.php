<?php

namespace App\Models;

class StudentElectionResult extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';
}
