<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MpesaTransaction extends Model
{
    use HasFactory;

    protected $fillable = [

        'school_id',

        'learner_id',

        'payment_id',

        'gateway_id',

        'merchant_request_id',

        'checkout_request_id',

        'mpesa_receipt_number',

        'phone_number',

        'account_reference',

        'transaction_desc',

        'amount',

        'transaction_date',

        'result_code',

        'result_description',

        'status',

        'callback_payload',

        'processed_by',

        'is_reconciled',

        'reconciled_at',

    ];

    protected $casts = [

        'amount' => 'decimal:2',

        'transaction_date' => 'datetime',

        'callback_payload' => 'array',

        'is_reconciled' => 'boolean',

        'reconciled_at' => 'datetime',

        'created_at' => 'datetime',

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
     * Learner
     */
    public function learner()
    {
        return $this->belongsTo(

            Learner::class,

            'learner_id'

        );
    }

    /**
     * Payment
     */
    public function payment()
    {
        return $this->belongsTo(

            Payment::class,

            'payment_id'

        );
    }
}
