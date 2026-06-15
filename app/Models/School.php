<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class School extends Model
{
    protected $table = 'schools';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'school_name',
        'school_code',
        'email',
        'phone',
        'county',
        'sub_county',
        'postal_address',
        'physical_address',
        'logo_url',
        'active',
        'school_type',
        'ownership',
        'registration_number',
        'kra_pin',
        'website'
    ];
}
