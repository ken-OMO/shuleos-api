<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;

class TenantService
{
    /**
     * Current school ID
     */
    public static function schoolId(): ?string
    {
        return Auth::user()?->school_id;
    }

    /**
     * Current authenticated user
     */
    public static function user()
    {
        return Auth::user();
    }

    /**
     * Check if tenant exists
     */
    public static function hasTenant(): bool
    {
        return Auth::check()

            && !empty(

                Auth::user()->school_id

            );
    }
}
