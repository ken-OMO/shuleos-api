<?php

namespace App\Models;

class HomeworkSubmission extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['submitted_at' => 'datetime', 'is_late' => 'boolean'];

    public function assignment()
    {
        return $this->belongsTo(HomeworkAssignment::class);
    }

    public function assignmentLearner()
    {
        return $this->belongsTo(HomeworkAssignmentLearner::class);
    }

    public function files()
    {
        return $this->hasMany(HomeworkSubmissionFile::class, 'submission_id');
    }

    public function mark()
    {
        return $this->hasOne(HomeworkSubmissionMark::class, 'submission_id');
    }
}
