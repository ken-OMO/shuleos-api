<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademicWeek extends Model
{
    protected $table = 'academic_weeks';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',
        'school_id',
        'academic_year_id',
        'term_id',
        'week_number',
        'start_date',
        'end_date',
        'active',
        'created_at',

    ];

    protected $casts = [

        'start_date' => 'date',
        'end_date' => 'date',
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
public function schemeLessons()
{
    return $this->hasMany(
        SchemeLesson::class,
        'week_id'
    );
}
}
