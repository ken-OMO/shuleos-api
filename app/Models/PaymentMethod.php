<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [

        'school_id',

        'method_name',

        'is_online',

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
 * Payments
 */
public function payments()
{
    return $this->hasMany(

        Payment::class,

        'payment_method_id'

    );
}
}
