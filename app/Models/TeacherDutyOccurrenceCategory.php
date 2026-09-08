<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class TeacherDutyOccurrenceCategory extends TenantModel
{
    use HasUuids;

    protected $table = 'teacher_duty_occurrence_categories';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'code',
        'name',
        'description',
        'display_order',
    ];

    protected $casts = [
        'is_canonical' => 'boolean',
        'display_order' => 'integer',
        'active' => 'boolean',
        'deactivated_at' => 'datetime',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'deactivated_by'
        );
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(
            TeacherDutyOccurrence::class,
            'occurrence_category_id'
        );
    }

    protected function performDeleteOnModel(): void
    {
        throw new LogicException(
            'Teacher duty occurrence categories cannot be deleted.'
        );
    }
}
