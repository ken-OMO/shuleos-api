<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentElection extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['voting_opens_at' => 'datetime', 'voting_closes_at' => 'datetime', 'is_deleted' => 'boolean'];

    /** @return HasMany<StudentElectionPosition, $this> */
    public function electionPositions(): HasMany
    {
        return $this->hasMany(StudentElectionPosition::class, 'election_id');
    }

    /**
     * @param  Builder<static>  $q
     * @return Builder<static>
     */
    public function scopeCurrent(Builder $q): Builder
    {
        return $q->where('is_deleted', false);
    }
}
