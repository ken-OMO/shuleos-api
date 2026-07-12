<?php

namespace App\Models;

class ReportCardAccessOverride extends TenantModel
{
    protected $table = 'report_card_access_overrides';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'school_id', 'learner_id', 'exam_id', 'report_card_id', 'access_scope', 'access_allowed', 'reason', 'approved_by', 'expires_at', 'is_deleted', 'deleted_at', 'deleted_by'];

    protected $casts = ['access_allowed' => 'boolean', 'expires_at' => 'datetime', 'is_deleted' => 'boolean', 'deleted_at' => 'datetime'];

    public function learner()
    {
        return $this->belongsTo(Learner::class);
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function reportCard()
    {
        return $this->belongsTo(ReportCard::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeCurrent($q)
    {
        return $q->where('is_deleted', false)->where(fn ($x) => $x->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
