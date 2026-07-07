<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningArea extends Model
{
    protected $table = 'learning_areas';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'learning_area_name',

        'short_name',

        'category',

        'is_core',

        'is_examined',

        'is_custom',

        'active',

        'created_at',

    ];

    protected $casts = [

        'is_core' => 'boolean',

        'is_examined' => 'boolean',

        'is_custom' => 'boolean',

        'active' => 'boolean',

        'created_at' => 'datetime',

    ];

    /**
     * Education Levels
     */
    public function educationLevels()
    {
        return $this->belongsToMany(

            EducationLevel::class,

            'level_learning_areas',

            'learning_area_id',

            'level_id'

        );
    }

    /**
     * Grade Allocations
     */
    public function allocations()
    {
        return $this->hasMany(

            LearningAreaAllocation::class,

            'learning_area_id'

        );
    }
/**
 * Teacher Assignments
 */
public function teacherAssignments()
{
    return $this->hasMany(

        TeacherAssignment::class,

        'learning_area_id'

    );
}

/**
 * Schemes Of Work
 */
public function schemesOfWork()
{
    return $this->hasMany(

        SchemeOfWork::class,

        'learning_area_id'

    );
}

/**
 * Lesson Plans
 */
public function lessonPlans()
{
    return $this->hasMany(

        LessonPlan::class,

        'learning_area_id'

    );
}

/**
 * Curriculum Coverage
 */
public function curriculumCoverage()
{
    return $this->hasMany(

        CurriculumCoverage::class,

        'learning_area_id'

    );
}

}
