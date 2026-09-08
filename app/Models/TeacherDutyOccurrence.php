<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TeacherDutyOccurrence extends TenantModel
{
    use HasUuids;

    protected $table = 'teacher_duty_occurrences';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'duty_period_id',
        'occurrence_category_id',
        'occurrence_date',
        'occurrence_time',
        'description',
    ];

    protected $casts = [
        'occurrence_date' => 'date',
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(
            TeacherDutyOccurrenceCategory::class,
            'occurrence_category_id'
        );
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'recorded_by'
        );
    }

    protected function performDeleteOnModel(): void
    {
        throw new LogicException(
            'Teacher duty occurrences cannot be deleted.'
        );
    }
}
