<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearnerModeOfStudyHistory extends Model
{
    protected $table = 'learner_mode_of_study_history';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'school_id',
        'learner_id',
        'from_mode',
        'to_mode',
        'reason',
        'changed_by',
        'changed_at',
        'created_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
