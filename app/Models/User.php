<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use Notifiable;

    protected $table = 'users';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'id',
        'school_id',
        'role_id',
        'username',
        'password_hash',
        'email',
        'phone',
        'first_name',
        'middle_name',
        'last_name',
        'active',
        'first_login',
    ];

    protected $hidden = [
        'password_hash',
        'password_reset_token',
        'mfa_secret',
    ];

    protected $casts = [
        'active' => 'boolean',
        'first_login' => 'boolean',
        'mfa_enabled' => 'boolean',
        'is_deleted' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'last_login' => 'datetime',
        'password_changed_at' => 'datetime',
        'deleted_at' => 'datetime',
        'account_locked_until' => 'datetime',
        'password_reset_expires' => 'datetime',
        'last_failed_login' => 'datetime',
    ];

    /**
     * Use password_hash column instead of password
     */
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    /**
     * School Relationship
     */
    public function school()
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    /**
     * Primary Role Relationship
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Additional Roles
     */
    public function roles()
    {
        return $this->belongsToMany(
            Role::class,
            'user_roles',
            'user_id',
            'role_id'
        );
    }

    /**
     * Teacher Profile
     */
    public function teacher()
    {
        return $this->hasOne(Teacher::class, 'user_id');
    }

    /**
     * JWT Identifier
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * JWT Custom Claims
     */
    public function getJWTCustomClaims()
    {
        return [
            'school_id' => $this->school_id,
            'role_id' => $this->role_id,
            'username' => $this->username,
        ];
    }
}
