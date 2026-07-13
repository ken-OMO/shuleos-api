<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomeworkSubmissionFile extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['storage_id', 'source_hash', 'stored_hash'];

    public function submission()
    {
        return $this->belongsTo(HomeworkSubmission::class);
    }
}
