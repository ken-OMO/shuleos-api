<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'is_absent',

        'entered_by',

        'created_at',
        'is_deleted', 'deleted_at', 'deleted_by',

    ];

    protected $casts = [

        'marks' => 'decimal:2',
        'is_absent' => 'boolean',

        'created_at' => 'datetime',
        'is_deleted' => 'boolean', 'deleted_at' => 'datetime',

    ];

    /**
     * @return BelongsTo<Exam, $this>
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(

            Exam::class,

            'exam_id'

        );
    }

    /**
     * @return BelongsTo<Learner, $this>
     */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(

            Learner::class,

            'learner_id'

        );
    }

    /**
     * @return BelongsTo<LearningArea, $this>
     */
    public function learningArea(): BelongsTo
    {
        return $this->belongsTo(

            LearningArea::class,

            'learning_area_id'

        );
    }

    /**
     * @return BelongsTo<ExamPaper, $this>
     */
    public function paper(): BelongsTo
    {
        return $this->belongsTo(

            ExamPaper::class,

            'paper_id'

        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(

            User::class,

            'entered_by'

        );
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_deleted', false);
    }
}
