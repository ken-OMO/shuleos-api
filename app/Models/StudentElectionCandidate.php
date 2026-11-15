<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentElectionCandidate extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['reviewed_at' => 'datetime', 'is_deleted' => 'boolean'];

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** @return BelongsTo<StudentElectionPosition, $this> */
    public function electionPosition(): BelongsTo
    {
        return $this->belongsTo(StudentElectionPosition::class);
    }
}
