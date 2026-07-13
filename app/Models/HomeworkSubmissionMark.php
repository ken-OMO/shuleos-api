<?php

namespace App\Models;

class HomeworkSubmissionMark extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['marked_at' => 'datetime', 'released_at' => 'datetime', 'raw_score' => 'decimal:2', 'percentage' => 'decimal:2', 'final_score' => 'decimal:2', 'late_penalty_applied' => 'decimal:2'];

    protected $hidden = ['private_teacher_notes'];

    public function submission()
    {
        return $this->belongsTo(HomeworkSubmission::class);
    }

    public function rubricScores()
    {
        return $this->hasMany(HomeworkSubmissionRubricScore::class, 'submission_mark_id');
    }
}
