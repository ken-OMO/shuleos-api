<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ParentPaymentAttempt extends TenantModel
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['school_id', 'parent_user_id', 'idempotency_key_hash', 'phone_hash', 'provider_request_id', 'checkout_request_id', 'merchant_request_id', 'provider_receipt', 'confirmed_amount_minor', 'confirmed_currency'];

    protected $casts = ['amount_minor' => 'integer', 'confirmed_amount_minor' => 'integer', 'initiated_at' => 'datetime', 'accepted_at' => 'datetime', 'completed_at' => 'datetime', 'expired_at' => 'datetime'];

    public function learner()
    {
        return $this->belongsTo(Learner::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice()
    {
        return $this->belongsTo(FeeInvoice::class);
    }

    public function history()
    {
        return $this->hasMany(ParentPaymentAttemptHistory::class, 'payment_attempt_id');
    }
}
