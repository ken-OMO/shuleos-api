<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class TeacherDutyWeeklyReport extends TenantModel
{
    use HasUuids;

    protected $table = 'teacher_duty_weekly_reports';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:sP';

    public $timestamps = true;

    protected $fillable = [
        'summary',
        'highlights',
        'challenges',
        'recommendations',
    ];

    protected $casts = [
        'evidence_snapshot' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'submitted_by'
        );
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'reviewed_by'
        );
    }

    public function history(): HasMany
    {
        return $this->hasMany(
            TeacherDutyWeeklyReportHistory::class,
            'weekly_report_id'
        );
    }

    protected function performDeleteOnModel(): void
    {
        throw new LogicException(
            'Teacher duty weekly reports cannot be deleted.'
        );
    }
}
