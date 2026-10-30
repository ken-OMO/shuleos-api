<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends TenantModel
{
    protected $table = 'exams';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'school_id',

        'exam_name',

        'assessment_type_id',

        'academic_year_id',

        'term_id',

        'start_date',

        'end_date',

        'active',
        'status',

        'created_by',

        'created_at',
        'is_deleted', 'deleted_at', 'deleted_by',

    ];

    protected $casts = [

        'start_date' => 'date',

        'end_date' => 'date',

        'active' => 'boolean',

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
     * @return BelongsTo<AssessmentType, $this>
     */
    public function assessmentType(): BelongsTo
    {
        return $this->belongsTo(

            AssessmentType::class,

            'assessment_type_id'

        );
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(

            AcademicYear::class,

            'academic_year_id'

        );
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(

            Term::class,

            'term_id'

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

    /**
     * @return HasMany<ExamLearningArea, $this>
     */
    public function learningAreas(): HasMany
    {
        return $this->hasMany(

            ExamLearningArea::class,

            'exam_id'

        );
    }

    /**
     * @return HasMany<ExamResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(

            ExamResult::class,

            'exam_id'

        );
    }

    /**
     * @return HasMany<MarkEntryPermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(

            MarkEntryPermission::class,

            'exam_id'

        );
    }

    /**
     * @return HasMany<MeritList, $this>
     */
    public function meritLists(): HasMany
    {
        return $this->hasMany(

            MeritList::class,

            'exam_id'

        );
    }

    /**
     * @return HasMany<ReportCard, $this>
     */
    public function reportCards(): HasMany
    {
        return $this->hasMany(

            ReportCard::class,

            'exam_id'

        );
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_deleted', false);
    }
}
