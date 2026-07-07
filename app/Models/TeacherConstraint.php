<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeacherConstraint extends Model
{
    use HasFactory;

    protected $fillable = [

        'school_id',

        'teacher_id',

        'constraint_type',

        'constraint_value',

        'priority',

        'active',

        'notes',

    ];
    public function school()
{
    return $this->belongsTo(

        School::class,

        'school_id'

    );
}

public function teacher()
{
    return $this->belongsTo(

        Teacher::class,

        'teacher_id'

    );
}
}
