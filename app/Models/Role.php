<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $table = 'roles';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'role_name',
        'school_id',
        'system_role',
        'active',
    ];

    protected $casts = ['system_role' => 'boolean', 'active' => 'boolean'];

    /**
     * Users whose primary role is this role
     */
    public function users()
    {
        return $this->hasMany(User::class, 'role_id');
    }

    /**
     * Users with this role through user_roles table
     */
    public function assignedUsers()
    {
        return $this->belongsToMany(
            User::class,
            'user_roles',
            'role_id',
            'user_id'
        );
    }
}
