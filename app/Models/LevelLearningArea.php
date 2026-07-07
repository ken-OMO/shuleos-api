<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LevelLearningArea extends Model
{
    protected $table = 'level_learning_areas';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'level_id',

        'learning_area_id',

        'created_at',

    ];

    protected $casts = [

        'created_at' => 'datetime',

    ];

    /**
     * Education Level relationship
     */
    public function educationLevel()
    {
        return $this->belongsTo(

            EducationLevel::class,

            'level_id'

        );
    }

    /**
     * Learning Area relationship
     */
    public function learningArea()
    {
        return $this->belongsTo(

            LearningArea::class,

            'learning_area_id'

        );
    }
}
