<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hostel extends TenantModel
{
    use HasUuids;

    protected $table = 'hostels';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'hostel_name',
        'hostel_type',
        'capacity',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'active' => 'boolean',
        'is_deleted' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(
            School::class,
            'school_id'
        );
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(
            HostelRoom::class,
            'hostel_id'
        );
    }

    public function scopeCurrent($query)
    {
        return $query
            ->where('active', true)
            ->where('is_deleted', false);
    }
}
