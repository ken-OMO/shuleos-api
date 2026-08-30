<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearnerLifecycleHistory extends Model
{
    protected $table = 'learner_lifecycle_history';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'school_id',
        'learner_id',
        'from_status',
        'to_status',
        'effective_date',
        'reason',
        'changed_by',
        'changed_at',
        'created_at',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'changed_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
