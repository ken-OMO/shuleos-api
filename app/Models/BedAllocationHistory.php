<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BedAllocationHistory extends Model
{
    protected $table = 'bed_allocation_history';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'school_id',
        'learner_id',
        'event_id',
        'event_type',
        'source_allocation_id',
        'destination_allocation_id',
        'from_status',
        'to_status',
        'effective_date',
        'reason',
        'changed_by',
        'changed_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \LogicException(
                'Bed allocation history is immutable.'
            );
        });

        static::deleting(function (): never {
            throw new \LogicException(
                'Bed allocation history is immutable.'
            );
        });
    }

    protected $casts = [
        'effective_date' => 'date',
        'changed_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
