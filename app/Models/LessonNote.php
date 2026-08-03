<?php

namespace App\Models;

class LessonNote extends TenantModel
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
        'is_deleted',
        'deleted_at',
        'deleted_by',

    ];

    protected $casts = [

        'created_at' => 'datetime',
        'is_deleted' => 'boolean',
        'deleted_at' => 'datetime',

    ];

    public function lessonPlan()
    {
        return $this->belongsTo(
            LessonPlan::class,
            'lesson_plan_id'
        );
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_deleted', false);
    }
}
