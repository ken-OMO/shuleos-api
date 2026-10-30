<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'email_verified_at',

        'phone',

        'first_name',
        'middle_name',
        'last_name',

        'active',
        'first_login',

        'temporary_password',
        'temporary_password_expires_at',
        'invitation_generation',
        'activated_at',

        'auth_generation',

        'suspended_at',
        'force_password_reset_at',
    ];

    protected $hidden = [
        'password_hash',
        'password_reset_token',
        'mfa_secret',
    ];

    protected $casts = [
        'active' => 'boolean',
        'first_login' => 'boolean',

        'temporary_password' => 'boolean',

        'mfa_enabled' => 'boolean',
        'is_deleted' => 'boolean',

        'email_verified_at' => 'datetime',
        'temporary_password_expires_at' => 'datetime',
        'activated_at' => 'datetime',

        'created_at' => 'datetime',
        'updated_at' => 'datetime',

        'last_login' => 'datetime',
        'password_changed_at' => 'datetime',

        'deleted_at' => 'datetime',

        'account_locked_until' => 'datetime',
        'password_reset_expires' => 'datetime',
        'last_failed_login' => 'datetime',

        'suspended_at' => 'datetime',
        'force_password_reset_at' => 'datetime',

        'auth_generation' => 'integer',
        'invitation_generation' => 'integer',
    ];

    /**
     * Use password_hash column instead of Laravel's default password column.
     */
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    /**
     * School relationship.
     *
     * Platform-level users may have school_id = null.
     *
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(
            School::class,
            'school_id'
        );
    }

    /**
     * Primary role relationship.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(
            Role::class,
            'role_id'
        );
    }

    /**
     * Additional roles.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'user_roles',
            'user_id',
            'role_id'
        );
    }

    /**
     * Teacher profile.
     *
     * @return HasOne<Teacher, $this>
     */
    public function teacher(): HasOne
    {
        return $this->hasOne(
            Teacher::class,
            'user_id'
        );
    }

    /**
     * Learner profile.
     *
     * @return HasOne<Learner, $this>
     */
    public function learner(): HasOne
    {
        return $this->hasOne(
            Learner::class,
            'user_id'
        );
    }

    /**
     * Guardian profile.
     *
     * @return HasOne<Guardian, $this>
     */
    public function guardian(): HasOne
    {
        return $this->hasOne(
            Guardian::class,
            'user_id'
        );
    }

    /**
     * Authentication challenges for OTP/MFA.
     *
     * @return HasMany<AuthenticationChallenge, $this>
     */
    public function authenticationChallenges(): HasMany
    {
        return $this->hasMany(
            AuthenticationChallenge::class,
            'user_id'
        );
    }

    /**
     * Authentication sessions/devices.
     *
     * @return HasMany<AuthenticationSession, $this>
     */
    public function authenticationSessions(): HasMany
    {
        return $this->hasMany(
            AuthenticationSession::class,
            'user_id'
        );
    }

    /**
     * Determine whether the current password is a temporary
     * provisioning credential.
     */
    public function hasTemporaryPassword(): bool
    {
        return (bool) $this->temporary_password;
    }

    /**
     * Determine whether the temporary password has expired.
     */
    public function temporaryPasswordExpired(): bool
    {
        return $this->temporary_password
            && $this->temporary_password_expires_at
            && $this->temporary_password_expires_at->isPast();
    }

    /**
     * Determine whether the user must replace their password.
     */
    public function requiresPasswordReset(): bool
    {
        return (bool) (
            $this->first_login
            || $this->force_password_reset_at
            || $this->temporary_password
        );
    }

    /**
     * Determine whether the email is verified and usable
     * for OTP or account recovery.
     */
    public function hasVerifiedEmail(): bool
    {
        return filled($this->email)
            && $this->email_verified_at !== null;
    }

    /**
     * JWT identifier.
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * JWT custom claims.
     */
    public function getJWTCustomClaims()
    {
        return [
            'school_id' => $this->school_id,
            'role_id' => $this->role_id,
            'username' => $this->username,
            'auth_generation' => (int) ($this->auth_generation ?: 1),
        ];
    }
}
