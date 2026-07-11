<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exam extends Model
{
    protected $table = 'exams';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'school_id',

        'exam_name',

        'assessment_type_id',

        'academic_year_id',

        'term_id',

        'start_date',

        'end_date',

        'active',
        'status',

        'created_by',

        'created_at',
        'is_deleted','deleted_at','deleted_by',

    ];

    protected $casts = [

        'start_date' => 'date',

        'end_date' => 'date',

        'active' => 'boolean',

        'created_at' => 'datetime',
        'is_deleted'=>'boolean','deleted_at'=>'datetime',

    ];

    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }

    public function assessmentType()
    {
        return $this->belongsTo(

            AssessmentType::class,

            'assessment_type_id'

        );
    }

    public function academicYear()
    {
        return $this->belongsTo(

            AcademicYear::class,

            'academic_year_id'

        );
    }

    public function term()
    {
        return $this->belongsTo(

            Term::class,

            'term_id'

        );
    }

    public function creator()
    {
        return $this->belongsTo(

            User::class,

            'created_by'

        );
    }

    public function learningAreas()
    {
        return $this->hasMany(

            ExamLearningArea::class,

            'exam_id'

        );
    }

    public function results()
    {
        return $this->hasMany(

            ExamResult::class,

            'exam_id'

        );
    }

    public function permissions()
    {
        return $this->hasMany(

            MarkEntryPermission::class,

            'exam_id'

        );
    }

    public function meritLists()
    {
        return $this->hasMany(

            MeritList::class,

            'exam_id'

        );
    }

    public function reportCards()
    {
        return $this->hasMany(

            ReportCard::class,

            'exam_id'

        );
    }
    public function scopeCurrent($query){return $query->where('is_deleted',false);}
}
