<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentElectionBallot extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['anonymous_ballot_token_hash'];

    protected $casts = ['invalidated_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $ballot): void {
            if ($ballot->isDirty()) {
                throw new \LogicException('Accepted ballots are immutable.');
            }
        });
        static::deleting(function (): void {
            throw new \LogicException('Accepted ballots are immutable.');
        });
    }
}
