<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BedAllocation extends TenantModel
{
    use HasUuids;

    protected $table = 'bed_allocations';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = true;

    protected $fillable = [
        'learner_id',
        'bed_id',
    ];

    protected $casts = [
        'allocation_date' => 'date',
        'release_date' => 'date',
        'active' => 'boolean',
        'status' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(
            School::class,
            'school_id'
        );
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(
            Learner::class,
            'learner_id'
        );
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(
            HostelBed::class,
            'bed_id'
        );
    }

    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'allocated_by'
        );
    }

    public function scopeCurrent($query)
    {
        return $query->where('active', true);
    }
}
