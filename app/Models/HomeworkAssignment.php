<?php

namespace App\Models;

class HomeworkAssignment extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['publish_at' => 'datetime', 'published_at' => 'datetime', 'due_at' => 'datetime', 'allow_late_submission' => 'boolean', 'allow_resubmission' => 'boolean', 'show_marks_immediately' => 'boolean', 'show_feedback_immediately' => 'boolean', 'is_deleted' => 'boolean', 'total_marks' => 'decimal:2', 'late_penalty_value' => 'decimal:2'];

    public function teacherAssignment()
    {
        return $this->belongsTo(TeacherAssignment::class);
    }

    public function learners()
    {
        return $this->hasMany(HomeworkAssignmentLearner::class, 'assignment_id');
    }

    public function submissions()
    {
        return $this->hasMany(HomeworkSubmission::class, 'assignment_id');
    }

    public function resources()
    {
        return $this->belongsToMany(LearningResource::class, 'homework_assignment_resources', 'assignment_id', 'learning_resource_id')->withPivot('display_order', 'required');
    }

    public function rubric()
    {
        return $this->hasOne(HomeworkRubric::class, 'assignment_id');
    }

    public function scopeCurrent($q)
    {
        return $q->where('is_deleted', false);
    }
}
