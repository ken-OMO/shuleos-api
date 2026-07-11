<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamResult extends Model
{
    protected $table = 'exam_results';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'exam_id',

        'learner_id',

        'learning_area_id',

        'paper_id',

        'marks',

        'entered_by',

        'created_at',
        'is_deleted','deleted_at','deleted_by',

    ];

    protected $casts = [

        'marks' => 'decimal:2',

        'created_at' => 'datetime',
        'is_deleted'=>'boolean','deleted_at'=>'datetime',

    ];

    public function exam()
    {
        return $this->belongsTo(

            Exam::class,

            'exam_id'

        );
    }

    public function learner()
    {
        return $this->belongsTo(

            Learner::class,

            'learner_id'

        );
    }

    public function learningArea()
    {
        return $this->belongsTo(

            LearningArea::class,

            'learning_area_id'

        );
    }

    public function paper()
    {
        return $this->belongsTo(

            ExamPaper::class,

            'paper_id'

        );
    }

    public function enteredBy()
    {
        return $this->belongsTo(

            User::class,

            'entered_by'

        );
    }
    public function scopeCurrent($query){return $query->where('is_deleted',false);}
}
