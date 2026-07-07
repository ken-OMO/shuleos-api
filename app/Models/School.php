<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class School extends Model
{
    protected $table = 'schools';

    protected $primaryKey = 'id';

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

        'website',

    ];

    protected $casts = [

        'active' => 'boolean',

        'is_deleted' => 'boolean',

        'created_at' => 'datetime',

        'updated_at' => 'datetime',

        'deleted_at' => 'datetime',

    ];

    /**
     * Grades
     */
    public function grades()
    {
        return $this->hasMany(

            Grade::class,

            'school_id'

        );
    }

    /**
     * Streams
     */
    public function streams()
    {
        return $this->hasMany(

            Stream::class,

            'school_id'

        );
    }

    /**
     * Teachers
     */
    public function teachers()
    {
        return $this->hasMany(

            Teacher::class,

            'school_id'

        );
    }

    /**
     * Learners
     */
    public function learners()
    {
        return $this->hasMany(

            Learner::class,

            'school_id'

        );
    }

    /**
     * Guardians
     */
    public function guardians()
    {
        return $this->hasMany(

            Guardian::class,

            'school_id'

        );
    }


}
