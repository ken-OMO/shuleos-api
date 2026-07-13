<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningResourceVersion extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['storage_id', 'source_hash', 'stored_hash'];

    public function resource()
    {
        return $this->belongsTo(LearningResource::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
