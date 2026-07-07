<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchemeOfWork extends Model
{
    protected $table = 'schemes_of_work';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',
        'school_id',
        'learning_area_id',
        'grade_id',
        'academic_year_id',
        'term_id',
        'title',
        'active',
        'created_by',
        'created_at',

    ];

    protected $casts = [

        'active' => 'boolean',
        'created_at' => 'datetime',

    ];

    public function school()
    {
        return $this->belongsTo(
            School::class,
            'school_id'
        );
    }

    public function learningArea()
    {
        return $this->belongsTo(
            LearningArea::class,
            'learning_area_id'
        );
    }

    public function grade()
    {
        return $this->belongsTo(
            Grade::class,
            'grade_id'
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

    public function lessons()
    {
        return $this->hasMany(
            SchemeLesson::class,
            'scheme_id'
        );
    }
}
