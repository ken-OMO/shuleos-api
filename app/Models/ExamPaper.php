<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamPaper extends Model
{
    protected $table = 'exam_papers';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'exam_learning_area_id',

        'paper_name',

        'paper_number',

        'max_marks',

        'created_at',

    ];

    protected $casts = [

        'paper_number' => 'integer',

        'max_marks' => 'integer',

        'created_at' => 'datetime',

    ];

    public function examLearningArea()
    {
        return $this->belongsTo(

            ExamLearningArea::class,

            'exam_learning_area_id'

        );
    }

    public function results()
    {
        return $this->hasMany(

            ExamResult::class,

            'paper_id'

        );
    }
}
