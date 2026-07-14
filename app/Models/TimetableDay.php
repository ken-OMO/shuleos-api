<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TimetableDay extends TenantModel
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public function profile()
    {
        return $this->belongsTo(TimetableProfile::class, 'timetable_profile_id');
    }
}
