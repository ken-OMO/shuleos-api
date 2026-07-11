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
        'opens_at','closes_at',

        'created_at',
        'is_deleted','deleted_at','deleted_by',

    ];

    protected $casts = [

        'active' => 'boolean',

        'created_at' => 'datetime',
        'opens_at'=>'datetime','closes_at'=>'datetime','is_deleted'=>'boolean','deleted_at'=>'datetime',

    ];

    public function exam()
    {
        return $this->belongsTo(

            Exam::class,

            'exam_id'

        );
    }
    public function scopeCurrent($query){return $query->where('is_deleted',false);}
    public function isOpen():bool{return $this->active&&(!$this->opens_at||$this->opens_at->isPast())&&(!$this->closes_at||$this->closes_at->isFuture());}
}
