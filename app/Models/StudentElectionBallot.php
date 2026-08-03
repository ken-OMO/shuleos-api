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

    protected $casts = ['cast_at' => 'datetime', 'invalidated_at' => 'datetime'];
}
