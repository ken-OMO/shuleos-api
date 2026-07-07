<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomConstraint extends Model
{
    protected $fillable = [

        'school_id',

        'room_id',

        'learning_area_id',

        'constraint_type',

        'constraint_value',

        'active',

    ];
    public function school()
{
    return $this->belongsTo(

        School::class,

        'school_id'

    );
}

public function learningArea()
{
    return $this->belongsTo(

        LearningArea::class,

        'learning_area_id'

    );
}
}
