<?php

namespace App\Models;

class StudentElectionPosition extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['candidate_eligibility_rules' => 'array', 'voter_eligibility_rules' => 'array', 'active' => 'boolean'];

    public function election()
    {
        return $this->belongsTo(StudentElection::class);
    }

    public function position()
    {
        return $this->belongsTo(StudentLeadershipPosition::class);
    }

    public function candidates()
    {
        return $this->hasMany(StudentElectionCandidate::class, 'election_position_id');
    }
}
