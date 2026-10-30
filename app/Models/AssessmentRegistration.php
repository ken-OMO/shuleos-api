<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentRegistration extends TenantModel
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
        'is_deleted', 'deleted_at', 'deleted_by',

    ];

    protected $casts = [

        'assessment_year' => 'integer',

        'created_at' => 'datetime',
        'is_deleted' => 'boolean', 'deleted_at' => 'datetime',

    ];

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }

    /**
     * @return BelongsTo<Learner, $this>
     */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(

            Learner::class,

            'learner_id'

        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(

            User::class,

            'created_by'

        );
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_deleted', false);
    }
}
