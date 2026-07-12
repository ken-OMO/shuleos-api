<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearnerParent extends Model
{
    protected $table = 'learner_parents';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [

        'id',

        'learner_id',

        'parent_id',

        'is_primary_contact',

        'active',

        'created_at',
        'relationship', 'receives_sms', 'receives_email', 'receives_report_cards', 'portal_enabled', 'emergency_contact', 'can_pick_learner', 'linked_by', 'linked_at', 'is_deleted', 'deleted_at', 'deleted_by',

    ];

    protected $casts = [

        'is_primary_contact' => 'boolean',

        'active' => 'boolean',

        'created_at' => 'datetime',
        'receives_sms' => 'boolean', 'receives_email' => 'boolean', 'receives_report_cards' => 'boolean', 'portal_enabled' => 'boolean', 'emergency_contact' => 'boolean', 'can_pick_learner' => 'boolean', 'linked_at' => 'datetime', 'is_deleted' => 'boolean', 'deleted_at' => 'datetime',

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

    public function linkedBy()
    {
        return $this->belongsTo(User::class, 'linked_by');
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function scopeCurrent($q)
    {
        return $q->where('active', true)->where('portal_enabled', true)->where('is_deleted', false);
    }
}
