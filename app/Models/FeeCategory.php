<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FeeCategory extends TenantModel
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [

        'id', 'school_id',

        'category_name',

        'description',

        'is_system',

        'active',

        'is_deleted', 'deleted_at', 'deleted_by',

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
