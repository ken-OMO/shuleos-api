<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeeStructure extends Model
{
    use HasFactory;

    protected $fillable = [

        'school_id',

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
