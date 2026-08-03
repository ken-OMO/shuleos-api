<?php

namespace App\Models;

class LearnerHelpRequest extends TenantModel
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['school_id', 'learner_id', 'created_by', 'destination_role'];

    protected $casts = ['submitted_at' => 'datetime', 'closed_at' => 'datetime'];
}
