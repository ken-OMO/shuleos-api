<?php

declare(strict_types=1);

namespace App\Services\TeacherDuty;

use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TeacherDutyOccurrenceCategoryProvisioningService
{
    private const CANONICAL_CATEGORIES = [
        ['discipline', 'Discipline', 10],
        ['attendance', 'Attendance', 20],
        ['health_safety', 'Health & Safety', 30],
        ['cleanliness', 'Cleanliness', 40],
        ['property_facilities', 'Property & Facilities', 50],
        ['academic', 'Academic', 60],
        ['visitor_security', 'Visitor & Security', 70],
        ['general', 'General', 80],
    ];

    public function provision(School $school): void
    {
        foreach (self::CANONICAL_CATEGORIES as [
            $code,
            $name,
            $displayOrder,
        ]) {
            $existing = DB::table(
                'teacher_duty_occurrence_categories'
            )
                ->where('school_id', $school->id)
                ->where('code', $code)
                ->first();

            if ($existing !== null) {
                if (! $existing->is_canonical) {
                    throw new RuntimeException(
                        'Teacher Duty occurrence category provisioning aborted: reserved canonical code collision detected.'
                    );
                }

                continue;
            }

            DB::table(
                'teacher_duty_occurrence_categories'
            )->insert([
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'code' => $code,
                'name' => $name,
                'description' => null,
                'is_canonical' => true,
                'display_order' => $displayOrder,
                'active' => true,
                'created_by' => null,
                'deactivated_by' => null,
                'deactivated_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
