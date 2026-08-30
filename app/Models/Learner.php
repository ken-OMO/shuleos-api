<?php

namespace App\Models;

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
     */
    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function dashboardPreference()
    {
        return $this->hasOne(LearnerDashboardPreference::class);
    }

    public function scopePortalActive($q)
    {
        return $q->where('active', true)->where('portal_enabled', true)->where('is_deleted', false);
    }

    /**
     * Grade relationship
     */
    public function grade()
    {
        return $this->belongsTo(

            Grade::class,

            'grade_id'

        );
    }

    /**
     * Stream relationship
     */
    public function stream()
    {
        return $this->belongsTo(

            Stream::class,

            'stream_id'

        );
    }

    /**
     * Guardians relationship
     */
    public function guardians()
    {
        return $this->belongsToMany(

            Guardian::class,

            'learner_parents',

            'learner_id',

            'parent_id'

        );
    }

    public function parentLinks()
    {
        return $this->hasMany(LearnerParent::class, 'learner_id');
    }

    public function publishedReportCards()
    {
        return $this->hasMany(ReportCard::class)->current()->where('status', 'published');
    }

    /***************
     * Finance
     ***************/

    /**
     * Learner fee account
     */
    public function feeAccount()
    {
        return $this->hasOne(

            LearnerFeeAccount::class,

            'learner_id'

        );
    }

    /**
     * Fee invoices
     */
    public function invoices()
    {
        return $this->hasMany(

            FeeInvoice::class,

            'learner_id'

        );
    }

    /**
     * Payments
     */
    public function payments()
    {
        return $this->hasMany(

            Payment::class,

            'learner_id'

        );
    }

    /**
     * M-Pesa transactions
     */
    public function mpesaTransactions()
    {
        return $this->hasMany(

            MpesaTransaction::class,

            'learner_id'

        );
    }
}
