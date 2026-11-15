<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** @return BelongsTo<StudentLeadershipPosition, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(StudentLeadershipPosition::class);
    }

    public function candidates()
    {
        return $this->hasMany(StudentElectionCandidate::class, 'election_position_id');
    }
}
