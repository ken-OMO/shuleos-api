<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FinanceSetting extends TenantModel
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [

        'id', 'school_id',

        'currency',

        'allow_partial_payments',

        'allow_overpayments',

        'auto_generate_invoices',

        'require_fee_clearance_for_results',

        'require_fee_clearance_for_report_cards',

        'require_fee_clearance_for_exams',

        'clearance_threshold',

        'reminder_due_soon_days',

        'finance_reminders_enabled',

        'active',

    ];

    /**
     * School
     */
    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }
}
