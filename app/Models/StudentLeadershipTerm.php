<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentLeadershipTerm extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** @return BelongsTo<StudentLeadershipPosition, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(StudentLeadershipPosition::class);
    }

    public function scopeCurrent($q)
    {
        return $q->where('is_deleted', false);
    }
}
