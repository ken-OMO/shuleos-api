<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LessonNote extends Model
{
    protected $table = 'lesson_notes';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',
        'school_id',
        'lesson_plan_id',
        'note_content',
        'created_by',
        'created_at',

    ];

    protected $casts = [

        'created_at' => 'datetime',

    ];

    public function lessonPlan()
    {
        return $this->belongsTo(
            LessonPlan::class,
            'lesson_plan_id'
        );
    }
}
