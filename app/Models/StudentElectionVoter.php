<?php

namespace App\Models;

class StudentElectionVoter extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['voting_token_hash'];

    protected $casts = ['eligible' => 'boolean', 'has_voted' => 'boolean', 'voted_at' => 'datetime'];
}
