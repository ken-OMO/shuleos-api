<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [

        'school_id',

        'learner_id',

        'invoice_id',

        'payment_method_id',

        'receipt_number',

        'amount',

        'allocated_amount',

        'payment_channel',

        'transaction_reference',

        'payment_date',

        'received_by',

        'payment_status',

        'reversed',

        'reversal_reason',

        'reversed_at',

        'reversed_by',

        'payer_phone',

        'payer_name',

        'remarks',

        'posted_by',

    ];
    public function school()
{
    return $this->belongsTo(

        School::class,

        'school_id'

    );
}

public function learner()
{
    return $this->belongsTo(

        Learner::class,

        'learner_id'

    );
}

public function invoice()
{
    return $this->belongsTo(

        FeeInvoice::class,

        'invoice_id'

    );
}

public function paymentMethod()
{
    return $this->belongsTo(

        PaymentMethod::class,

        'payment_method_id'

    );
}

public function allocations()
{
    return $this->hasMany(

        PaymentAllocation::class,

        'payment_id'

    );
}
}
