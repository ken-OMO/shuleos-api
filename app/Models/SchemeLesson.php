<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchemeLesson extends Model
{
    protected $table = 'scheme_lessons';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',
        'scheme_id',
        'week_id',
        'lesson_number',
        'strand',
        'sub_strand',
        'specific_learning_outcome',
        'learning_experience',
        'resources',
        'assessment_method',
        'created_at',
        'is_deleted',
        'deleted_at',
        'deleted_by',

    ];

    protected $casts = [

        'created_at' => 'datetime',
        'is_deleted' => 'boolean',
        'deleted_at' => 'datetime',

    ];

    public function scheme()
    {
        return $this->belongsTo(
            SchemeOfWork::class,
            'scheme_id'
        );
    }

    public function week()
    {
        return $this->belongsTo(
            AcademicWeek::class,
            'week_id'
        );
    }

    public function lessonPlans()
    {
        return $this->hasMany(
            LessonPlan::class,
            'scheme_lesson_id'
        );
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_deleted', false);
    }
}
