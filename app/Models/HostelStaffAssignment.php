<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class HostelStaffAssignment extends TenantModel
{
    use HasUuids;

    protected $table = 'hostel_staff_assignments';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'hostel_id',
        'user_id',
        'responsibility_role',
        'effective_from',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'active' => 'boolean',
        'ended_at' => 'datetime',
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

    public function hostel(): BelongsTo
    {
        return $this->belongsTo(
            Hostel::class,
            'hostel_id'
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'user_id'
        );
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'assigned_by'
        );
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'ended_by'
        );
    }

    public function scopeCurrent($query)
    {
        return $query->where('active', true);
    }

    /**
     * Responsibility episodes are historical lifecycle records.
     *
     * They may only be closed through BoardingStaffResponsibilityService.
     * Generic model deletion is deliberately forbidden.
     */
    protected function performDeleteOnModel(): void
    {
        throw new LogicException(
            'Hostel staff responsibility assignments cannot be deleted.'
        );
    }
}
