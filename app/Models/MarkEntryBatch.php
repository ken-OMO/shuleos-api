<?php

namespace App\Models;

class MarkEntryBatch extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['submitted_at' => 'datetime', 'moderated_at' => 'datetime', 'locked_at' => 'datetime'];

    public function items()
    {
        return $this->hasMany(MarkEntryBatchItem::class, 'batch_id');
    }
}
