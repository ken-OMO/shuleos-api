<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearnerParent extends Model
{
    protected $table = 'learner_parents';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'learner_id',

        'parent_id',

        'is_primary_contact',

        'active',

        'created_at',

    ];

    protected $casts = [

        'is_primary_contact' => 'boolean',

        'active' => 'boolean',

        'created_at' => 'datetime',

    ];

    /**
     * Learner relationship
     */
    public function learner()
    {
        return $this->belongsTo(

            Learner::class,

            'learner_id'

        );
    }

    /**
     * Guardian relationship
     */
    public function guardian()
    {
        return $this->belongsTo(

            Guardian::class,

            'parent_id'

        );
    }
}
