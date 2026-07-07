<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssessmentRegistration extends Model
{
    protected $table = 'assessment_registrations';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'school_id',

        'learner_id',

        'assessment_type',

        'assessment_year',

        'candidate_number',

        'registration_number',

        'status',

        'created_by',

        'created_at',

    ];

    protected $casts = [

        'assessment_year' => 'integer',

        'created_at' => 'datetime',

    ];

    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }

    public function learner()
    {
        return $this->belongsTo(

            Learner::class,

            'learner_id'

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
