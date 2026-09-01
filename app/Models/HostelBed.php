<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostelBed extends TenantModel
{
    use HasUuids;

    protected $table = 'hostel_beds';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'bed_number',
    ];

    protected $casts = [
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

    public function room(): BelongsTo
    {
        return $this->belongsTo(
            HostelRoom::class,
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
