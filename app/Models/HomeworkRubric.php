<?php

namespace App\Models;

class HomeworkRubric extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    public function criteria()
    {
        return $this->hasMany(HomeworkRubricCriterion::class, 'rubric_id')->orderBy('display_order');
    }
}
