<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimetableConstraint extends Model
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
