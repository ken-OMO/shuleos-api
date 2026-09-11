<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class TeacherDutyPeriod extends TenantModel
{
    use HasUuids;

    protected $table = 'teacher_duty_periods';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'academic_week_id',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
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

    public function academicWeek(): BelongsTo
    {
        return $this->belongsTo(
            AcademicWeek::class,
            'academic_week_id'
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'ended_by'
        );
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(
            TeacherDutyAssignment::class,
            'duty_period_id'
        );
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(
            TeacherDutyOccurrence::class,
            'duty_period_id'
        );
    }

    public function dailyReports(): HasMany
    {
        return $this->hasMany(
            TeacherDutyDailyReport::class,
            'duty_period_id'
        );
    }

    public function scopeCurrent($query)
    {
        return $query->where('active', true);
    }

    /**
     * Duty periods are preserved lifecycle records.
     *
     * They may only be closed through TeacherDutyRosterService.
     * Generic model deletion is deliberately forbidden.
     */
    protected function performDeleteOnModel(): void
    {
        throw new LogicException(
            'Teacher duty periods cannot be deleted.'
        );
    }
}
