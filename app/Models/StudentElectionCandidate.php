<?php

namespace App\Models;

class StudentElectionCandidate extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['reviewed_at' => 'datetime', 'is_deleted' => 'boolean'];

    public function learner()
    {
        return $this->belongsTo(Learner::class);
    }

    public function electionPosition()
    {
        return $this->belongsTo(StudentElectionPosition::class);
    }
}
