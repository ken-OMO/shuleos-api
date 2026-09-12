<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TeacherDutyWeeklyReportHistory extends TenantModel
{
    use HasUuids;

    protected $table = 'teacher_duty_weekly_report_history';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [
        '*',
    ];

    protected $casts = [
        'evidence_snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(
            School::class,
            'school_id'
        );
    }

    public function weeklyReport(): BelongsTo
    {
        return $this->belongsTo(
            TeacherDutyWeeklyReport::class,
            'weekly_report_id'
        );
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'actor_user_id'
        );
    }

    protected function performUpdate(Builder $query)
    {
        throw new LogicException(
            'Teacher duty weekly report history cannot be updated.'
        );
    }

    protected function performDeleteOnModel(): void
    {
        throw new LogicException(
            'Teacher duty weekly report history cannot be deleted.'
        );
    }
}
