<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FeeStructure extends TenantModel
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [

        'id', 'school_id',

        'academic_year_id',

        'term_id',

        'grade_id',

        'stream_id',

        'fee_category_id',

        'payment_plan_id',

        'amount',

        'due_date',

        'notes',

        'active',

        'status', 'revision', 'approved_by', 'approved_at', 'activated_at', 'archived_at', 'created_by', 'is_deleted',

    ];

    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

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

    public function feeCategory()
    {
        return $this->belongsTo(

            FeeCategory::class,

            'fee_category_id'

        );
    }

    public function paymentPlan()
    {
        return $this->belongsTo(

            PaymentPlan::class,

            'payment_plan_id'

        );
    }

    public function invoices()
    {
        return $this->hasMany(

            FeeInvoice::class,

            'fee_structure_id'

        );
    }
}
