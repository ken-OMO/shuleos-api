<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'school_id',

        'user_id',

        'module',

        'action',

        'table_name',

        'record_id',

        'description',

        'old_values',

        'new_values',

        'ip_address',

        'user_agent',

        'created_at',

    ];

    protected $casts = [

        'old_values' => 'array',

        'new_values' => 'array',

        'created_at' => 'datetime',

    ];

    /**
     * School Relationship
     */
    public function school()
    {
        return $this->belongsTo(

            School::class,

            'school_id'

        );
    }

    /**
     * User Relationship
     */
    public function user()
    {
        return $this->belongsTo(

            User::class,

            'user_id'

        );
    }
}
