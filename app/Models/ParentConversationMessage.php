<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ParentConversationMessage extends TenantModel
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['school_id', 'sender_user_id'];

    protected $casts = ['sent_at' => 'datetime', 'created_at' => 'datetime'];
}
