<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarkEntryPermission extends Model
{
    protected $table = 'mark_entry_permissions';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [

        'id',

        'exam_id',

        'role_name',

        'active',

        'created_at',

    ];

    protected $casts = [

        'active' => 'boolean',

        'created_at' => 'datetime',

    ];

    public function exam()
    {
        return $this->belongsTo(

            Exam::class,

            'exam_id'

        );
    }
}
