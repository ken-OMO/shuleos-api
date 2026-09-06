<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearnerPlacementHistory extends Model
{
    protected $table = 'learner_placement_history';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'school_id',
        'learner_id',
        'from_grade_id',
        'from_stream_id',
        'to_grade_id',
        'to_stream_id',
        'placement_type',
        'reason',
        'placed_by',
        'placed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'placed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
