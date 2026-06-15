<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Teacher extends Model
{
    protected $table = 'teachers';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'id',
        'school_id',
        'user_id',
        'tsc_no',
        'staff_no',
        'gender',
        'designation',
        'employment_type',
        'phone',
        'email',
        'national_id',
        'date_joined',
        'active',
        'is_deleted',
        'deleted_at',
        'deleted_by',
    ];

    protected $casts = [
        'active' => 'boolean',
        'is_deleted' => 'boolean',
        'date_joined' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * User Account
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * School
     */
    public function school()
    {
        return $this->belongsTo(School::class, 'school_id');
    }
}
