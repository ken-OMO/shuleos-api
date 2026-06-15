<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EducationLevel extends Model
{
    protected $table = 'education_levels';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'level_name',
        'level_order',
        'active',
        'created_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'created_at' => 'datetime',
    ];

    /**
     * Grades relationship
     */
    public function grades()
    {
        return $this->hasMany(
            Grade::class,
            'education_level_id'
        );
    }
}
