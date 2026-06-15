<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Grade extends Model
{
    protected $table = 'grades';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    // grades table only has created_at
    public $timestamps = false;

    protected $fillable = [
        'id',
        'school_id',
        'education_level_id',
        'grade_name',
        'grade_order',
        'active',
        'created_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'created_at' => 'datetime',
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
     * Education Level relationship
     */
    public function educationLevel()
    {
        return $this->belongsTo(
            EducationLevel::class,
            'education_level_id'
        );
    }

    /**
     * Streams relationship
     */
    public function streams()
    {
        return $this->hasMany(
            Stream::class,
            'grade_id'
        );
    }
}
