<?php

namespace App\Models;

class StudentLeadershipTerm extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    public function learner()
    {
        return $this->belongsTo(Learner::class);
    }

    public function position()
    {
        return $this->belongsTo(StudentLeadershipPosition::class);
    }

    public function scopeCurrent($q)
    {
        return $q->where('is_deleted', false);
    }
}
