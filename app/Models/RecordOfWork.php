<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecordOfWork extends Model
{
    protected $table = 'records_of_work';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',
        'school_id',
        'lesson_plan_id',
        'date_taught',
        'content_covered',
        'learner_response',
        'teacher_reflection',
        'status',
        'created_by',
        'created_at',
        'is_deleted','deleted_at','deleted_by',

    ];

    protected $casts = [

        'date_taught' => 'date',
        'created_at' => 'datetime',
        'is_deleted'=>'boolean','deleted_at'=>'datetime',

    ];

    public function lessonPlan()
    {
        return $this->belongsTo(
            LessonPlan::class,
            'lesson_plan_id'
        );
    }

    public function curriculumCoverage()
    {
        return $this->hasOne(
            CurriculumCoverage::class,
            'record_of_work_id'
        );
    }
    public function scopeCurrent($query){return $query->where('is_deleted',false);}
}
