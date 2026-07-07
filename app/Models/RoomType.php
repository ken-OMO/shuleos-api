<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomType extends Model
{
    protected $fillable = [

        'school_id',

        'type_name',

        'description',

        'active',

    ];
}
