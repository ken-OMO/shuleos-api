<?php

namespace App\Models;

class StudentElectionTallySnapshot extends TenantModel
{
    protected $table = 'student_election_tally_snapshots';

    protected $guarded = [];

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['payload' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (): void {
            throw new \LogicException('Tally snapshots are immutable.');
        });
        static::deleting(function (): void {
            throw new \LogicException('Tally snapshots are immutable.');
        });
    }
}
