<?php

namespace App\Models;

class DisciplineAction extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $casts = ['action_date' => 'date', 'start_at' => 'datetime', 'due_at' => 'datetime', 'completed_at' => 'datetime', 'follow_up_required' => 'boolean', 'is_deleted' => 'boolean'];

    public function case()
    {
        return $this->belongsTo(DisciplineCase::class, 'case_id');
    }
}
