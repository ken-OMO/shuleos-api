<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Guardian extends Model
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

        );
    }
}
