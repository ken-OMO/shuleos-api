<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class PaymentPlan extends TenantModel
{
    use HasFactory;

    protected $fillable = [

        'school_id',

        'plan_name',

        'description',

        'number_of_installments',

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

    /**
     * Fee Structures
     */
    public function feeStructures()
    {
        return $this->hasMany(

            FeeStructure::class,

            'payment_plan_id'

        );
    }
}
