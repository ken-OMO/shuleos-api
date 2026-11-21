<?php

namespace App\Services\Auth;

use App\Models\School;
use App\Models\User;

class SchoolLoginIdentityService
{
    public function find(string $identifier, ?string $schoolCode): ?User
    {
        $identifier = trim($identifier);
        $query = User::query()->whereNotNull('school_id');
        if (filled($schoolCode)) {
            $schools = School::query()->whereRaw('LOWER(school_code) = ?', [strtolower(trim($schoolCode))])->limit(2)->get();
            if ($schools->count() !== 1) {
                return null;
            }
            $query->where('school_id', $schools->first()->id);
        } elseif (str_contains($identifier, '@')) {
            return null;
        } else {
            // Transitional support for existing generated usernames, never for invited email identities.
            $query->where('requires_school_code', false);
        }
        $matches = $query->whereRaw('LOWER(username) = ?', [strtolower($identifier)])->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
