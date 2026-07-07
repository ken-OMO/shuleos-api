<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LearnerFeeAccount extends Model
{
    use HasFactory;

    protected $fillable = [

        'school_id',

        'learner_id',

        'account_number',

        'current_balance',

        'credit_limit',

        'last_payment_date',

        'account_status',

        'active',

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
}
