<?php

namespace App\Models;

class Guardian extends TenantModel
{
    protected $table = 'parents';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'school_id',

        'user_id',

        'first_name',

        'last_name',

        'phone',

        'email',

        'relationship',

        'active',

        'created_at',

    ];

    protected $casts = [

        'active' => 'boolean',

        'is_deleted' => 'boolean',

        'created_at' => 'datetime',

        'deleted_at' => 'datetime',

    ];

    /**
     * School relationship
     */
    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }

    /**
     * User relationship
     */
    public function user()
    {
        return $this->belongsTo(

            User::class,

            'user_id'

        );
    }

    /**
     * Learners relationship
     */
    public function learners()
    {
        return $this->belongsToMany(

            Learner::class,

            'learner_parents',

            'parent_id',

            'learner_id'

        )->withPivot(['id', 'relationship', 'is_primary_contact', 'active', 'portal_enabled', 'receives_sms', 'receives_email', 'receives_report_cards', 'emergency_contact', 'can_pick_learner', 'linked_at', 'is_deleted']);
    }

    public function learnerLinks()
    {
        return $this->hasMany(LearnerParent::class, 'parent_id');
    }

    public function scopeCurrent($q)
    {
        return $q->where('active', true)->where('is_deleted', false);
    }
}
