<?php

namespace App\Models;

class StudentElectionVoter extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    // Participation updates must not record the moment a secret ballot was cast.
    public $timestamps = false;

    protected $casts = ['eligible' => 'boolean', 'has_voted' => 'boolean'];
}
