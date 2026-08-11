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
        'id',

        'school_name',

        /*
         * Permanent prefix used when generating school user usernames.
         *
         * Example:
         * Lakeview Junior School
         * login_prefix = LJS
         *
         * Usernames may then become:
         * LJS4827
         * LJS1953
         *
         * This should not automatically change when the school name changes.
         */
        'login_prefix',

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

        'short_name',

        'motto',

        'lifecycle_state',

        'lifecycle_version',

        'timezone',

        'locale',

        'academic_contact',

        'finance_contact',

        'communication_contact',
    ];

    protected $casts = [
        'active' => 'boolean',

        'is_deleted' => 'boolean',

        'lifecycle_version' => 'integer',

        'created_at' => 'datetime',

        'updated_at' => 'datetime',

        'deleted_at' => 'datetime',

        'suspended_at' => 'datetime',

        'locked_at' => 'datetime',

        'archived_at' => 'datetime',
    ];

    /**
     * School settings.
     */
    public function settings()
    {
        return $this->hasOne(
            SchoolSettings::class,
            'school_id'
        );
    }

    /**
     * Grades belonging to the school.
     */
    public function grades()
    {
        return $this->hasMany(
            Grade::class,
            'school_id'
        );
    }

    /**
     * Streams belonging to the school.
     */
    public function streams()
    {
        return $this->hasMany(
            Stream::class,
            'school_id'
        );
    }

    /**
     * Teachers belonging to the school.
     */
    public function teachers()
    {
        return $this->hasMany(
            Teacher::class,
            'school_id'
        );
    }

    /**
     * Learners belonging to the school.
     */
    public function learners()
    {
        return $this->hasMany(
            Learner::class,
            'school_id'
        );
    }

    /**
     * Guardians belonging to the school.
     */
    public function guardians()
    {
        return $this->hasMany(
            Guardian::class,
            'school_id'
        );
    }

    /**
     * Users belonging to the school.
     *
     * This will be useful during onboarding, administration,
     * security auditing and tenant lifecycle operations.
     */
    public function users()
    {
        return $this->hasMany(
            User::class,
            'school_id'
        );
    }

    /**
     * Roles created specifically for this school.
     */
    public function roles()
    {
        return $this->hasMany(
            Role::class,
            'school_id'
        );
    }
}
