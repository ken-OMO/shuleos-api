<?php

namespace App\Services;

use App\Models\School;
use Illuminate\Support\Facades\DB;

class SchoolSetupReadinessService
{
    private const PROFILE_FIELDS = [
        'school_name',
        'short_name',
        'registration_number',
        'school_type',
        'county',
        'phone',
        'email',
        'timezone',
        'locale',
    ];

    public function readiness(string $schoolId): array
    {
        $school = School::query()
            ->withoutGlobalScopes()
            ->whereKey($schoolId)
            ->first();

        if (! $school) {
            return $this->notReady($schoolId);
        }

        $profileComplete = collect(self::PROFILE_FIELDS)
            ->every(fn (string $field): bool => filled($school->{$field}));

        $steps = [
            'school_profile' => $profileComplete,

            'academic_year' => DB::table('academic_years')
                ->where('school_id', $schoolId)
                ->where('active', true)
                ->exists(),

            'current_term' => DB::table('terms')
                ->where('school_id', $schoolId)
                ->where('active', true)
                ->exists(),

            'grades' => DB::table('grades')
                ->where('school_id', $schoolId)
                ->where('active', true)
                ->exists(),

            'streams' => DB::table('streams')
                ->where('school_id', $schoolId)
                ->where('active', true)
                ->exists(),
        ];

        return [
            'school_id' => $schoolId,
            'setup_complete' => ! in_array(false, $steps, true),
            'steps' => $steps,
        ];
    }

    public function isReady(string $schoolId): bool
    {
        return (bool) $this->readiness($schoolId)['setup_complete'];
    }

    private function notReady(string $schoolId): array
    {
        return [
            'school_id' => $schoolId,
            'setup_complete' => false,
            'steps' => [
                'school_profile' => false,
                'academic_year' => false,
                'current_term' => false,
                'grades' => false,
                'streams' => false,
            ],
        ];
    }
}
