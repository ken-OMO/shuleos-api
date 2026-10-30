<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Learner extends TenantModel
{
    protected $table = 'learners';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [

        'id',

        'school_id',

        'admission_no',

        'upi',

        'kemis_no',

        'first_name',

        'middle_name',

        'last_name',

        'gender',

        'date_of_birth',

        'grade_id',

        'stream_id',

        'admission_date',

        'assessment_no',

        'active',
        'user_id', 'portal_enabled', 'portal_activated_at',

    ];

    protected $casts = [

        'date_of_birth' => 'date',

        'admission_date' => 'date',

        'active' => 'boolean',
        'lifecycle_status' => 'string',
        'portal_enabled' => 'boolean', 'portal_activated_at' => 'datetime',

        'is_deleted' => 'boolean',

        'created_at' => 'datetime',

        'updated_at' => 'datetime',

        'deleted_at' => 'datetime',

    ];

    /**
     * School relationship
     *
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<LearnerDashboardPreference, $this>
     */
    public function dashboardPreference(): HasOne
    {
        return $this->hasOne(LearnerDashboardPreference::class);
    }

    public function scopePortalActive($q)
    {
        return $q->where('active', true)->where('portal_enabled', true)->where('is_deleted', false);
    }

    /**
     * Grade relationship
     *
     * @return BelongsTo<Grade, $this>
     */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(

            Grade::class,

            'grade_id'

        );
    }

    /**
     * Stream relationship
     *
     * @return BelongsTo<Stream, $this>
     */
    public function stream(): BelongsTo
    {
        return $this->belongsTo(

            Stream::class,

            'stream_id'

        );
    }

    /**
     * Guardians relationship
     *
     * @return BelongsToMany<Guardian, $this>
     */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(

            Guardian::class,

            'learner_parents',

            'learner_id',

            'parent_id'

        );
    }

    /**
     * @return HasMany<LearnerParent, $this>
     */
    public function parentLinks(): HasMany
    {
        return $this->hasMany(LearnerParent::class, 'learner_id');
    }

    /**
     * @return HasMany<ReportCard, $this>
     */
    public function publishedReportCards(): HasMany
    {
        return $this->hasMany(ReportCard::class)->current()->where('status', 'published');
    }

    /***************
     * Finance
     ***************/

    /**
     * Learner fee account
     *
     * @return HasOne<LearnerFeeAccount, $this>
     */
    public function feeAccount(): HasOne
    {
        return $this->hasOne(

            LearnerFeeAccount::class,

            'learner_id'

        );
    }

    /**
     * Fee invoices
     *
     * @return HasMany<FeeInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(

            FeeInvoice::class,

            'learner_id'

        );
    }

    /**
     * Payments
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(

            Payment::class,

            'learner_id'

        );
    }

    /**
     * M-Pesa transactions
     *
     * @return HasMany<MpesaTransaction, $this>
     */
    public function mpesaTransactions(): HasMany
    {
        return $this->hasMany(

            MpesaTransaction::class,

            'learner_id'

        );
    }
}
