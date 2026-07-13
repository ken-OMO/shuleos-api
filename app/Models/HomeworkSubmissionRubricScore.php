<?php

namespace App\Models;

class HomeworkSubmissionRubricScore extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    public function criterion()
    {
        return $this->belongsTo(HomeworkRubricCriterion::class);
    }

    public function level()
    {
        return $this->belongsTo(HomeworkRubricLevel::class);
    }
}
