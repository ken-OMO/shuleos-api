<?php

namespace App\Models;

class StudentElectionResult extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (): void {
            throw new \LogicException('Tallied results are immutable.');
        });
        static::deleting(function (): void {
            throw new \LogicException('Tallied results are immutable.');
        });
    }
}
