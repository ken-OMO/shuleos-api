<?php

namespace App\Models;

class ReportCard extends TenantModel
{
    protected $table = 'report_cards';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = ['id', 'school_id', 'learner_id', 'exam_id', 'academic_year_id', 'term_id', 'merit_list_id', 'grade_id', 'stream_id', 'overall_score', 'maximum_marks', 'average_percentage', 'overall_grade', 'overall_grading_system_id', 'overall_grading_scale_id', 'total_points', 'stream_position', 'grade_position', 'school_position', 'total_learners', 'attendance_present', 'attendance_absent', 'attendance_late', 'attendance_total_sessions', 'attendance_percentage', 'class_teacher_comment', 'principal_comment', 'pathway_recommendation', 'pathway_recommendation_id', 'status', 'generated_by', 'generated_at', 'published_by', 'published_at', 'is_deleted', 'deleted_at', 'deleted_by'];

    protected $casts = ['overall_score' => 'decimal:2', 'maximum_marks' => 'decimal:2', 'average_percentage' => 'decimal:2', 'total_points' => 'integer', 'stream_position' => 'integer', 'grade_position' => 'integer', 'school_position' => 'integer', 'total_learners' => 'integer', 'attendance_present' => 'integer', 'attendance_absent' => 'integer', 'attendance_late' => 'integer', 'attendance_total_sessions' => 'integer', 'attendance_percentage' => 'decimal:2', 'generated_at' => 'datetime', 'published_at' => 'datetime', 'is_deleted' => 'boolean', 'deleted_at' => 'datetime'];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function learner()
    {
        return $this->belongsTo(Learner::class);
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function meritList()
    {
        return $this->belongsTo(MeritList::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function stream()
    {
        return $this->belongsTo(Stream::class);
    }

    public function overallGradingSystem()
    {
        return $this->belongsTo(GradingSystem::class, 'overall_grading_system_id');
    }

    public function overallGradingScale()
    {
        return $this->belongsTo(GradingScale::class, 'overall_grading_scale_id');
    }

    public function pathwayRecommendation()
    {
        return $this->belongsTo(PathwayRecommendation::class);
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function publishedBy()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function learningAreas()
    {
        return $this->hasMany(ReportCardLearningArea::class)->current();
    }

    public function scopeCurrent($q)
    {
        return $q->where('is_deleted', false);
    }
}
