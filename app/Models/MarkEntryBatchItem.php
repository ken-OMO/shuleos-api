<?php

namespace App\Models;

class MarkEntryBatchItem extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['marks' => 'decimal:2', 'previous_marks' => 'decimal:2'];
}
