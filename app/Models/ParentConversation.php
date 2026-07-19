<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ParentConversation extends TenantModel
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['school_id', 'parent_user_id', 'resolved_staff_user_id'];

    protected $casts = ['safeguarding_restricted' => 'boolean', 'closed_at' => 'datetime'];

    public function learner()
    {
        return $this->belongsTo(Learner::class);
    }

    public function messages()
    {
        return $this->hasMany(ParentConversationMessage::class, 'conversation_id');
    }
}
