<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'is_deleted', 'deleted_at', 'deleted_by',

    ];

    protected $casts = [

        'paper_number' => 'integer',

        'max_marks' => 'integer',

        'created_at' => 'datetime',
        'is_deleted' => 'boolean', 'deleted_at' => 'datetime',

    ];

    /**
     * @return BelongsTo<ExamLearningArea, $this>
     */
    public function examLearningArea(): BelongsTo
    {
        return $this->belongsTo(

            ExamLearningArea::class,

            'exam_learning_area_id'

        );
    }

    /**
     * @return HasMany<ExamResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(

            ExamResult::class,

            'paper_id'

        );
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_deleted', false);
    }
}
