<?php

namespace App\Models;

class HomeworkRubricCriterion extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    public function levels()
    {
        return $this->hasMany(HomeworkRubricLevel::class, 'criterion_id')->orderBy('display_order');
    }
}
