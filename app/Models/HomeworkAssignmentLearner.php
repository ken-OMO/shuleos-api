<?php

namespace App\Models;

class HomeworkAssignmentLearner extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['assigned_at' => 'datetime', 'first_viewed_at' => 'datetime', 'last_viewed_at' => 'datetime'];

    public function assignment()
    {
        return $this->belongsTo(HomeworkAssignment::class);
    }

    public function learner()
    {
        return $this->belongsTo(Learner::class);
    }

    public function submissions()
    {
        return $this->hasMany(HomeworkSubmission::class, 'assignment_learner_id');
    }
}
