<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TeacherDutyAssignment extends TenantModel
{
    use HasUuids;

    protected $table = 'teacher_duty_assignments';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'duty_period_id',
        'teacher_id',
    ];

    protected $casts = [
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

    public function dutyPeriod(): BelongsTo
    {
        return $this->belongsTo(
            TeacherDutyPeriod::class,
            'duty_period_id'
        );
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(
            Teacher::class,
            'teacher_id'
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
     * Duty assignments are preserved lifecycle records.
     *
     * They may only be closed through TeacherDutyRosterService.
     * Generic model deletion is deliberately forbidden.
     */
    protected function performDeleteOnModel(): void
    {
        throw new LogicException(
            'Teacher duty assignments cannot be deleted.'
        );
    }
}
