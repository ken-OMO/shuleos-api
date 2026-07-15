<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PaymentAllocation extends TenantModel
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [

        'id', 'school_id',

        'payment_id',

        'invoice_id',

        'allocated_amount',

        'created_by',

        'status', 'ledger_entry_id', 'reversed_at', 'reversed_by', 'reversal_reason',

    ];

    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }

    public function payment()
    {
        return $this->belongsTo(

            Payment::class,

            'payment_id'

        );
    }

    public function invoice()
    {
        return $this->belongsTo(

            FeeInvoice::class,

            'invoice_id'

        );
    }
}
