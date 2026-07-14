<?php

namespace App\Models;

class DisciplineCase extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $casts = ['incident_date' => 'date', 'confidential' => 'boolean', 'safeguarding' => 'boolean', 'is_deleted' => 'boolean', 'reviewed_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function learner()
    {
        return $this->belongsTo(Learner::class);
    }

    public function category()
    {
        return $this->belongsTo(DisciplineCategory::class);
    }

    public function actions()
    {
        return $this->hasMany(DisciplineAction::class, 'case_id');
    }

    public function scopeCurrent($q)
    {
        return $q->where('is_deleted', false);
    }
}
