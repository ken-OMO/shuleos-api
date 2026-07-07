<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportCard extends Model
{
    protected $table = 'report_cards';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'school_id',

        'learner_id',

        'exam_id',

        'academic_year_id',

        'term_id',

        'overall_score',

        'overall_grade',

        'total_points',

        'stream_position',

        'grade_position',

        'school_position',

        'total_learners',

        'attendance_percentage',

        'class_teacher_comment',

        'principal_comment',

        'pathway_recommendation',

        'generated_at',

    ];

    protected $casts = [

        'overall_score' => 'decimal:2',

        'total_points' => 'decimal:2',

        'attendance_percentage' => 'decimal:2',

        'stream_position' => 'integer',

        'grade_position' => 'integer',

        'school_position' => 'integer',

        'total_learners' => 'integer',

        'generated_at' => 'datetime',

    ];

    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }

    public function learner()
    {
        return $this->belongsTo(

            Learner::class,

            'learner_id'

        );
    }

    public function exam()
    {
        return $this->belongsTo(

            Exam::class,

            'exam_id'

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
}
