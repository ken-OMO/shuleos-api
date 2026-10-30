<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * @property array<string, mixed>|null $grading_snapshot
 */
class ReportCard extends TenantModel
{
    protected $table = 'report_cards';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $hidden = ['presentation_snapshot'];

    protected $appends = ['presentation_history', 'publication_revision'];

    private ?int $archivedPublicationRevision = null;

    public function useArchivedPublicationRevision(int $revision): void
    {
        if ($revision < 1) {
            throw new \InvalidArgumentException('Publication revisions start at one.');
        }

        $this->archivedPublicationRevision = $revision;
    }

    public function getPublicationRevisionAttribute(): ?int
    {
        if ($this->status !== 'published') {
            return null;
        }

        if ($this->archivedPublicationRevision !== null) {
            return $this->archivedPublicationRevision;
        }

        return (int) (DB::table('assessment_publication_versions')
            ->where('school_id', $this->school_id)
            ->where('exam_id', $this->exam_id)
            ->where('output_type', 'report_card')
            ->where('output_id', $this->id)
            ->max('revision_number') ?? 1);
    }

    public function getPresentationHistoryAttribute(): array
    {
        return [
            'status' => $this->status !== 'published' ? 'preview'
                : ($this->presentation_snapshot === null ? 'legacy_unavailable' : 'captured'),
            'version' => $this->presentation_snapshot['version'] ?? null,
        ];
    }

    protected $fillable = ['presentation_snapshot', 'grading_snapshot', 'id', 'school_id', 'learner_id', 'exam_id', 'academic_year_id', 'term_id', 'merit_list_id', 'has_absence', 'grade_id', 'stream_id', 'overall_score', 'maximum_marks', 'average_percentage', 'overall_grade', 'overall_grading_system_id', 'overall_grading_scale_id', 'total_points', 'stream_position', 'grade_position', 'school_position', 'total_learners', 'attendance_present', 'attendance_absent', 'attendance_late', 'attendance_total_sessions', 'attendance_percentage', 'class_teacher_comment', 'principal_comment', 'pathway_recommendation', 'pathway_recommendation_id', 'status', 'generated_by', 'generated_at', 'published_by', 'published_at', 'is_deleted', 'deleted_at', 'deleted_by'];

    protected $casts = ['presentation_snapshot' => 'array', 'grading_snapshot' => 'array', 'has_absence' => 'boolean', 'overall_score' => 'decimal:2', 'maximum_marks' => 'decimal:2', 'average_percentage' => 'decimal:2', 'total_points' => 'integer', 'stream_position' => 'integer', 'grade_position' => 'integer', 'school_position' => 'integer', 'total_learners' => 'integer', 'attendance_present' => 'integer', 'attendance_absent' => 'integer', 'attendance_late' => 'integer', 'attendance_total_sessions' => 'integer', 'attendance_percentage' => 'decimal:2', 'generated_at' => 'datetime', 'published_at' => 'datetime', 'is_deleted' => 'boolean', 'deleted_at' => 'datetime'];

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * @return BelongsTo<Learner, $this>
     */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /**
     * @return BelongsTo<Exam, $this>
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * @return BelongsTo<MeritList, $this>
     */
    public function meritList(): BelongsTo
    {
        return $this->belongsTo(MeritList::class);
    }

    /**
     * @return BelongsTo<Grade, $this>
     */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    /**
     * @return BelongsTo<Stream, $this>
     */
    public function stream(): BelongsTo
    {
        return $this->belongsTo(Stream::class);
    }

    /**
     * @return BelongsTo<GradingSystem, $this>
     */
    public function overallGradingSystem(): BelongsTo
    {
        return $this->belongsTo(GradingSystem::class, 'overall_grading_system_id');
    }

    /**
     * @return BelongsTo<GradingScale, $this>
     */
    public function overallGradingScale(): BelongsTo
    {
        return $this->belongsTo(GradingScale::class, 'overall_grading_scale_id');
    }

    /**
     * @return BelongsTo<PathwayRecommendation, $this>
     */
    public function pathwayRecommendation(): BelongsTo
    {
        return $this->belongsTo(PathwayRecommendation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * @return HasMany<ReportCardLearningArea, $this>
     */
    public function learningAreas(): HasMany
    {
        return $this->hasMany(ReportCardLearningArea::class)->current();
    }

    public function scopeCurrent($q)
    {
        return $q->where('is_deleted', false);
    }

    /**
     * @return HasMany<ReportCardAccessOverride, $this>
     */
    public function accessOverrides(): HasMany
    {
        return $this->hasMany(ReportCardAccessOverride::class);
    }
}
