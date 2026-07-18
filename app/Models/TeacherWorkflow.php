<?php

namespace App\Models;

class TeacherWorkflow extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['approved_snapshot' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];

    public function history()
    {
        return $this->hasMany(TeacherWorkflowHistory::class, 'workflow_id');
    }
}
