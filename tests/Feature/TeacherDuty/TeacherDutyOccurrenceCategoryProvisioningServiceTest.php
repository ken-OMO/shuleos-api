<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherDuty;

use App\Models\School;
use App\Services\TeacherDuty\TeacherDutyOccurrenceCategoryProvisioningService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherDutyOccurrenceCategoryProvisioningServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_provisions_the_eight_canonical_categories(): void
    {
        $school = $this->school();

        $this->service()->provision($school);

        $categories = DB::table('teacher_duty_occurrence_categories')
            ->where('school_id', $school->id)
            ->orderBy('display_order')
            ->get();

        $this->assertCount(8, $categories);

        $this->assertSame(
            [
                ['discipline', 'Discipline', 10],
                ['attendance', 'Attendance', 20],
                ['health_safety', 'Health & Safety', 30],
                ['cleanliness', 'Cleanliness', 40],
                ['property_facilities', 'Property & Facilities', 50],
                ['academic', 'Academic', 60],
                ['visitor_security', 'Visitor & Security', 70],
                ['general', 'General', 80],
            ],
            $categories
                ->map(fn (object $category): array => [
                    $category->code,
                    $category->name,
                    $category->display_order,
                ])
                ->all()
        );

        foreach ($categories as $category) {
            $this->assertTrue($category->is_canonical);
            $this->assertTrue($category->active);
            $this->assertNull($category->description);
            $this->assertNull($category->created_by);
            $this->assertNull($category->deactivated_by);
            $this->assertNull($category->deactivated_at);
        }
    }

    public function test_repeated_provisioning_is_idempotent(): void
    {
        $school = $this->school();
        $service = $this->service();

        $service->provision($school);

        $idsBefore = DB::table('teacher_duty_occurrence_categories')
            ->where('school_id', $school->id)
            ->orderBy('code')
            ->pluck('id', 'code')
            ->all();

        $service->provision($school);

        $idsAfter = DB::table('teacher_duty_occurrence_categories')
            ->where('school_id', $school->id)
            ->orderBy('code')
            ->pluck('id', 'code')
            ->all();

        $this->assertCount(8, $idsAfter);
        $this->assertSame($idsBefore, $idsAfter);
    }

    public function test_repeated_provisioning_preserves_customized_canonical_state(): void
    {
        $school = $this->school();
        $service = $this->service();

        $service->provision($school);

        DB::table('teacher_duty_occurrence_categories')
            ->where('school_id', $school->id)
            ->where('code', 'discipline')
            ->update([
                'name' => 'School Discipline',
                'description' => 'Customized by the school.',
                'display_order' => 95,
                'active' => false,
                'deactivated_by' => $this->userId($school),
                'deactivated_at' => now(),
                'updated_at' => now(),
            ]);

        $before = DB::table('teacher_duty_occurrence_categories')
            ->where('school_id', $school->id)
            ->where('code', 'discipline')
            ->first();

        $service->provision($school);

        $after = DB::table('teacher_duty_occurrence_categories')
            ->where('school_id', $school->id)
            ->where('code', 'discipline')
            ->first();

        $this->assertSame($before->id, $after->id);
        $this->assertSame('School Discipline', $after->name);
        $this->assertSame('Customized by the school.', $after->description);
        $this->assertSame(95, $after->display_order);
        $this->assertFalse($after->active);
        $this->assertSame($before->deactivated_by, $after->deactivated_by);
        $this->assertSame(
            (string) $before->deactivated_at,
            (string) $after->deactivated_at
        );
        $this->assertNull($after->created_by);
    }

    private function service(): TeacherDutyOccurrenceCategoryProvisioningService
    {
        return app(
            TeacherDutyOccurrenceCategoryProvisioningService::class
        );
    }

    private function school(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Duty Occurrence '.Str::upper(
                Str::random(8)
            ),
            'school_code' => 'TDO-'.Str::upper(
                Str::random(8)
            ),
            'short_name' => 'TDO',
            'registration_number' => 'REG-'.Str::upper(
                Str::random(10)
            ),
            'school_type' => 'Primary',
            'county' => 'Nairobi',
            'phone' => '+2547'.random_int(
                10000000,
                99999999
            ),
            'email' => Str::lower(
                Str::random(10)
            ).'@example.test',
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
            'active' => true,
        ]);
    }

    private function userId(School $school): string
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'role_name' => 'Duty Occurrence '.Str::upper(
                Str::random(8)
            ),

            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $userId,
            'school_id' => $school->id,
            'role_id' => $roleId,
            'first_name' => 'Duty',
            'last_name' => 'Tester',
            'username' => 'duty_occurrence_'.Str::lower(
                Str::random(10)
            ),
            'email' => Str::lower(
                Str::random(10)
            ).'@example.test',
            'password_hash' => bcrypt('Password123!'),
            'active' => true,
            'first_login' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $userId;
    }
}
