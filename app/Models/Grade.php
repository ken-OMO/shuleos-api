<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Grade extends TenantModel
{
    protected $table = 'grades';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'school_id',

        'education_level_id',

        'grade_name',

        'grade_order',

        'grade_number',

        'active',

        'created_at',

    ];

    protected $casts = [

        'active' => 'boolean',

        'grade_number' => 'integer',

        'created_at' => 'datetime',

    ];

    /**
     * School relationship
     */
    public static function numberFromStandardName(string $name): ?int
    {
        $name = trim($name);

        if (preg_match('/^PP\s*[12]$/i', $name) === 1) {
            return 0;
        }

        if (preg_match('/^grade\s*(1[0-2]|[1-9])$/i', $name, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $grade): void {
            if ($grade->grade_number === null) {
                $grade->grade_number = self::numberFromStandardName(
                    (string) $grade->grade_name
                );
            }
        });
    }

    public function requiresAssessmentNumber(): bool
    {
        return $this->grade_number !== null
            && $this->grade_number >= 3
            && $this->grade_number <= 9;
    }

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
     * Education Level relationship
     *
     * @return BelongsTo<EducationLevel, $this>
     */
    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(

            EducationLevel::class,

            'education_level_id'

        );
    }

    /**
     * Streams relationship
     *
     * @return HasMany<Stream, $this>
     */
    public function streams(): HasMany
    {
        return $this->hasMany(

            Stream::class,

            'grade_id'

        );
    }

    /**
     * Learners relationship
     *
     * @return HasMany<Learner, $this>
     */
    public function learners(): HasMany
    {
        return $this->hasMany(

            Learner::class,

            'grade_id'

        );
    }
}
