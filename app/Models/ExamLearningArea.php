<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'is_deleted', 'deleted_at', 'deleted_by',

    ];

    protected $casts = [

        'number_of_papers' => 'integer',

        'total_marks' => 'integer',

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
     * @return HasMany<ExamPaper, $this>
     */
    public function papers(): HasMany
    {
        return $this->hasMany(

            ExamPaper::class,

            'exam_learning_area_id'

        );
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_deleted', false);
    }
}
