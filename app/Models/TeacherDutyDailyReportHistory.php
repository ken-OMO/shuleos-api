<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TeacherDutyDailyReportHistory extends TenantModel
{
    use HasUuids;

    protected $table = 'teacher_duty_daily_report_history';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [
        '*',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(
            School::class,
            'school_id'
        );
    }

    public function dailyReport(): BelongsTo
    {
        return $this->belongsTo(
            TeacherDutyDailyReport::class,
            'daily_report_id'
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
            'Teacher duty daily report history cannot be updated.'
        );
    }

    protected function performDeleteOnModel(): void
    {
        throw new LogicException(
            'Teacher duty daily report history cannot be deleted.'
        );
    }
}
