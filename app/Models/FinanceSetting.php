<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinanceSetting extends Model
{
    use HasFactory;

    protected $fillable = [

        'school_id',

        'currency',

        'allow_partial_payments',

        'allow_overpayments',

        'auto_generate_invoices',

        'require_fee_clearance_for_results',

        'require_fee_clearance_for_report_cards',

        'require_fee_clearance_for_exams',

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
