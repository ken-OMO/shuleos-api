<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class FeeCategory extends TenantModel
{
    use HasFactory;

    protected $fillable = [

        'school_id',

        'category_name',

        'description',

        'is_system',

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
     * Fee Structures
     */
    public function feeStructures()
    {
        return $this->hasMany(

            FeeStructure::class,

            'fee_category_id'

        );
    }
}
