<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentAllocation extends Model
{
    use HasFactory;

    protected $fillable = [

        'school_id',

        'payment_id',

        'invoice_id',

        'allocated_amount',

        'created_by',

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
