<?php

namespace App\Models;

class StudentElectionDeclaration extends TenantModel
{
    protected $table = 'student_election_declarations';

    protected $guarded = [];

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['term_ids' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (): void {
            throw new \LogicException('Election declarations are immutable.');
        });
        static::deleting(function (): void {
            throw new \LogicException('Election declarations are immutable.');
        });
    }
}
