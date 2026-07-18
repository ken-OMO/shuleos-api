<?php

namespace App\Models;

class TeacherAttachment extends TenantModel
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['school_id', 'user_id', 'teacher_id', 'storage_id', 'source_hash', 'stored_hash'];
}
