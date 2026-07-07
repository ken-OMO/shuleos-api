<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamLearningArea extends Model
{
    protected $table = 'exam_learning_areas';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'exam_id',

        'learning_area_id',

        'number_of_papers',

        'total_marks',

        'created_at',

    ];

    protected $casts = [

        'number_of_papers' => 'integer',

        'total_marks' => 'integer',

        'created_at' => 'datetime',

    ];

    public function exam()
    {
        return $this->belongsTo(

            Exam::class,

            'exam_id'

        );
    }

    public function learningArea()
    {
        return $this->belongsTo(

            LearningArea::class,

            'learning_area_id'

        );
    }

    public function papers()
    {
        return $this->hasMany(

            ExamPaper::class,

            'exam_learning_area_id'

        );
    }
}
