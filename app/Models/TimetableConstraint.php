<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class TimetableConstraint extends TenantModel
{
    use HasFactory;

    protected $fillable = [

        'school_id',

        'constraint_name',

        'constraint_category',

        'priority',

        'active',

        'description',

    ];

    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }
}
