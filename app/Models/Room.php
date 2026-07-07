<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Room extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'rooms';

    public $timestamps = false;

    protected $fillable = [

        'school_id',

        'room_type_id',

        'room_name',

        'room_code',

        'block_name',

        'floor_number',

        'capacity',

        'active',

        'created_by',

    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }

    public function roomType()
    {
        return $this->belongsTo(

            RoomType::class,

            'room_type_id'

        );
    }

    public function creator()
    {
        return $this->belongsTo(

            User::class,

            'created_by'

        );
    }
}
