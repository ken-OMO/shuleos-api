<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HostelRoom extends TenantModel
{
    use HasUuids;

    protected $table = 'hostel_rooms';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'room_name',
        'floor_number',
        'capacity',
    ];

    protected $casts = [
        'floor_number' => 'integer',
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

    public function hostel(): BelongsTo
    {
        return $this->belongsTo(
            Hostel::class,
            'hostel_id'
        );
    }

    public function beds(): HasMany
    {
        return $this->hasMany(
            HostelBed::class,
            'room_id'
        );
    }

    public function scopeCurrent($query)
    {
        return $query
            ->where('active', true)
            ->where('is_deleted', false);
    }
}
