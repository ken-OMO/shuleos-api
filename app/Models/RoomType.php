<?php

namespace App\Models;

class RoomType extends TenantModel
{
    protected $fillable = [

        'school_id',

        'type_name',

        'description',

        'active',

    ];
}
