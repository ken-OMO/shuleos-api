<?php

namespace App\Models;

class BehaviourRecognition extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['awarded_at' => 'datetime', 'approved_at' => 'datetime', 'visible_to_learner' => 'boolean', 'visible_to_parent' => 'boolean', 'is_deleted' => 'boolean'];

    public function learner()
    {
        return $this->belongsTo(Learner::class);
    }
}
