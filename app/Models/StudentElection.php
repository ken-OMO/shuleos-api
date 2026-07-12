<?php

namespace App\Models;

class StudentElection extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['voting_opens_at' => 'datetime', 'voting_closes_at' => 'datetime', 'is_deleted' => 'boolean'];

    public function electionPositions()
    {
        return $this->hasMany(StudentElectionPosition::class, 'election_id');
    }

    public function scopeCurrent($q)
    {
        return $q->where('is_deleted', false);
    }
}
