<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FeeInvoice extends TenantModel
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [

        'id', 'school_id',

        'learner_id',

        'academic_year_id',

        'term_id',

        'grade_id',

        'stream_id',

        'fee_structure_id',

        'invoice_number',

        'total_amount',

        'amount_paid',

        'balance',

        'status',

        'invoice_date',

        'due_date',

        'posted_at',

        'cancelled_at',

        'generated_by',

        'notes',

        'learner_fee_account_id', 'posted_by', 'cancelled_by', 'cancellation_reason',

    ];

    public function items()
    {
        return $this->hasMany(FeeInvoiceItem::class, 'invoice_id');
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class, 'invoice_id');
    }

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

    public function academicYear()
    {
        return $this->belongsTo(

            AcademicYear::class,

            'academic_year_id'

        );
    }

    public function term()
    {
        return $this->belongsTo(

            Term::class,

            'term_id'

        );
    }

    public function grade()
    {
        return $this->belongsTo(

            Grade::class,

            'grade_id'

        );
    }

    public function stream()
    {
        return $this->belongsTo(

            Stream::class,

            'stream_id'

        );
    }

    public function feeStructure()
    {
        return $this->belongsTo(

            FeeStructure::class,

            'fee_structure_id'

        );
    }

    public function payments()
    {
        return $this->hasMany(

            Payment::class,

            'invoice_id'

        );
    }
}
