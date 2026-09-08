<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherDuty;

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\TeacherDuty\TeacherDutyOccurrenceCategoryProvisioningService;
use App\Services\TeacherDuty\TeacherDutyOccurrenceService;
use App\Services\TeacherDuty\TeacherDutyRosterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TeacherDutyOccurrenceServiceTest extends TestCase
{
    use DatabaseTransactions;

    private TeacherDutyOccurrenceService $service;

    private TeacherDutyRosterService $roster;

    private TeacherDutyOccurrenceCategoryProvisioningService $provisioner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(
            TeacherDutyOccurrenceService::class
        );

        $this->roster = app(
            TeacherDutyRosterService::class
        );

        $this->provisioner = app(
            TeacherDutyOccurrenceCategoryProvisioningService::class
        );
    }

    public function test_records_occurrence_for_current_same_school_period_and_active_category(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $period = $this->period(
            $school,
            $actor
        );

        $categoryId = $this->canonicalCategoryId(
            $school,
            'discipline'
        );

        $occurrence = $this->service->recordOccurrence(
            (string) $school->id,
            (string) $period->id,
            $categoryId,
            '2026-09-09',
            '10:15:00',
            'Learner discipline incident recorded.',
            (string) $actor->id
        );

        $this->assertSame(
            (string) $school->id,
            (string) $occurrence->school_id
        );

        $this->assertSame(
            (string) $period->id,
            (string) $occurrence->duty_period_id
        );

        $this->assertSame(
            $categoryId,
            (string) $occurrence->occurrence_category_id
        );

        $this->assertSame(
            '2026-09-09',
            $occurrence->occurrence_date?->toDateString()
        );

        $this->assertSame(
            'Learner discipline incident recorded.',
            $occurrence->description
        );

        $this->assertSame(
            (string) $actor->id,
            (string) $occurrence->recorded_by
        );

        $this->assertDatabaseHas(
            'teacher_duty_occurrences',
            [
                'id' => $occurrence->id,
                'school_id' => $school->id,
                'duty_period_id' => $period->id,
                'occurrence_category_id' => $categoryId,
                'occurrence_date' => '2026-09-09',
                'description' => 'Learner discipline incident recorded.',
                'recorded_by' => $actor->id,
            ]
        );
    }

    public function test_occurrence_date_must_use_strict_yyyy_mm_dd_format(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $period = $this->period(
            $school,
            $actor
        );

        $categoryId = $this->canonicalCategoryId(
            $school,
            'discipline'
        );

        foreach (
            [
                '2026-9-9',
                '09-09-2026',
                '2026-09-31',
            ] as $date
        ) {
            $this->expectValidationField(
                'occurrence_date',
                fn () => $this->service->recordOccurrence(
                    (string) $school->id,
                    (string) $period->id,
                    $categoryId,
                    $date,
                    null,
                    'Strict date validation test.',
                    (string) $actor->id
                )
            );
        }

        $this->assertSame(
            0,
            DB::table('teacher_duty_occurrences')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_occurrence_description_cannot_be_whitespace_only(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $period = $this->period(
            $school,
            $actor
        );

        $categoryId = $this->canonicalCategoryId(
            $school,
            'discipline'
        );

        $this->expectValidationField(
            'description',
            fn () => $this->service->recordOccurrence(
                (string) $school->id,
                (string) $period->id,
                $categoryId,
                '2026-09-09',
                null,
                "   \t   ",
                (string) $actor->id
            )
        );

        $this->assertSame(
            0,
            DB::table('teacher_duty_occurrences')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_occurrence_date_must_fall_within_period_inclusively(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $period = $this->period(
            $school,
            $actor
        );

        $categoryId = $this->canonicalCategoryId(
            $school,
            'discipline'
        );

        foreach (
            [
                '2026-09-06',
                '2026-09-12',
            ] as $date
        ) {
            $this->expectValidationField(
                'occurrence_date',
                fn () => $this->service->recordOccurrence(
                    (string) $school->id,
                    (string) $period->id,
                    $categoryId,
                    $date,
                    null,
                    'Period boundary validation.',
                    (string) $actor->id
                )
            );
        }

        foreach (
            [
                '2026-09-07',
                '2026-09-11',
            ] as $date
        ) {
            $occurrence = $this->service->recordOccurrence(
                (string) $school->id,
                (string) $period->id,
                $categoryId,
                $date,
                null,
                'Inclusive period boundary.',
                (string) $actor->id
            );

            $this->assertSame(
                $date,
                $occurrence->occurrence_date?->toDateString()
            );
        }
    }

    public function test_occurrence_requires_current_active_period(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $period = $this->period(
            $school,
            $actor
        );

        $categoryId = $this->canonicalCategoryId(
            $school,
            'discipline'
        );

        $this->roster->endPeriod(
            (string) $school->id,
            (string) $period->id,
            (string) $actor->id
        );

        $this->expectValidationField(
            'period_id',
            fn () => $this->service->recordOccurrence(
                (string) $school->id,
                (string) $period->id,
                $categoryId,
                '2026-09-09',
                null,
                'Ended period rejection.',
                (string) $actor->id
            )
        );
    }

    public function test_occurrence_requires_active_same_school_category(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $actorA = $this->user($schoolA);
        $actorB = $this->user($schoolB);

        $periodA = $this->period(
            $schoolA,
            $actorA
        );

        $foreignCategoryId = $this->canonicalCategoryId(
            $schoolB,
            'discipline'
        );

        $this->expectValidationField(
            'occurrence_category_id',
            fn () => $this->service->recordOccurrence(
                (string) $schoolA->id,
                (string) $periodA->id,
                $foreignCategoryId,
                '2026-09-09',
                null,
                'Foreign category rejection.',
                (string) $actorA->id
            )
        );

        $categoryId = $this->canonicalCategoryId(
            $schoolA,
            'discipline'
        );

        DB::table('teacher_duty_occurrence_categories')
            ->where('id', $categoryId)
            ->update([
                'active' => false,
                'deactivated_by' => $actorA->id,
                'deactivated_at' => now(),
                'updated_at' => now(),
            ]);

        $this->expectValidationField(
            'occurrence_category_id',
            fn () => $this->service->recordOccurrence(
                (string) $schoolA->id,
                (string) $periodA->id,
                $categoryId,
                '2026-09-09',
                null,
                'Inactive category rejection.',
                (string) $actorA->id
            )
        );
    }

    public function test_occurrence_recorder_must_be_eligible_same_school_user(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $actorA = $this->user($schoolA);
        $foreignActor = $this->user($schoolB);

        $periodA = $this->period(
            $schoolA,
            $actorA
        );

        $categoryId = $this->canonicalCategoryId(
            $schoolA,
            'discipline'
        );

        $this->expectValidationField(
            'actor',
            fn () => $this->service->recordOccurrence(
                (string) $schoolA->id,
                (string) $periodA->id,
                $categoryId,
                '2026-09-09',
                null,
                'Foreign actor rejection.',
                (string) $foreignActor->id
            )
        );

        foreach (
            [
                ['active' => false],
                ['is_deleted' => true],
                ['suspended_at' => now()],
            ] as $state
        ) {
            $actor = $this->user($schoolA);

            DB::table('users')
                ->where('id', $actor->id)
                ->update($state);

            $this->expectValidationField(
                'actor',
                fn () => $this->service->recordOccurrence(
                    (string) $schoolA->id,
                    (string) $periodA->id,
                    $categoryId,
                    '2026-09-09',
                    null,
                    'Ineligible actor rejection.',
                    (string) $actor->id
                )
            );
        }
    }

    public function test_creates_custom_category_with_server_owned_provenance_and_initial_lifecycle(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $category = $this->service->createCustomCategory(
            (string) $school->id,
            'pastoral_support',
            'Pastoral Support',
            'Pastoral and learner welfare matters.',
            90,
            (string) $actor->id
        );

        $this->assertSame(
            (string) $school->id,
            (string) $category->school_id
        );

        $this->assertSame(
            'pastoral_support',
            $category->code
        );

        $this->assertSame(
            'Pastoral Support',
            $category->name
        );

        $this->assertSame(
            'Pastoral and learner welfare matters.',
            $category->description
        );

        $this->assertSame(
            90,
            $category->display_order
        );

        $this->assertFalse($category->is_canonical);
        $this->assertTrue($category->active);

        $this->assertSame(
            (string) $actor->id,
            (string) $category->created_by
        );

        $this->assertNull($category->deactivated_by);
        $this->assertNull($category->deactivated_at);

        $this->assertDatabaseHas(
            'teacher_duty_occurrence_categories',
            [
                'id' => $category->id,
                'school_id' => $school->id,
                'code' => 'pastoral_support',
                'is_canonical' => false,
                'active' => true,
                'created_by' => $actor->id,
                'deactivated_by' => null,
                'deactivated_at' => null,
            ]
        );
    }

    public function test_custom_category_rejects_reserved_and_invalid_codes(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        foreach (
            [
                'discipline',
                'Pastoral_Support',
                'pastoral-support',
                '_pastoral',
                'pastoral_',
                'pastoral__support',
                str_repeat('a', 101),
            ] as $code
        ) {
            $this->expectValidationField(
                'code',
                fn () => $this->service->createCustomCategory(
                    (string) $school->id,
                    $code,
                    'Pastoral Support',
                    null,
                    90,
                    (string) $actor->id
                )
            );
        }

        $this->assertSame(
            0,
            DB::table('teacher_duty_occurrence_categories')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_custom_category_requires_name_and_non_negative_display_order(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $this->expectValidationField(
            'name',
            fn () => $this->service->createCustomCategory(
                (string) $school->id,
                'pastoral_support',
                "   \t   ",
                null,
                90,
                (string) $actor->id
            )
        );

        $this->expectValidationField(
            'display_order',
            fn () => $this->service->createCustomCategory(
                (string) $school->id,
                'pastoral_support',
                'Pastoral Support',
                null,
                -1,
                (string) $actor->id
            )
        );
    }

    public function test_custom_category_creator_must_be_eligible_same_school_user(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $foreignActor = $this->user($schoolB);

        $this->expectValidationField(
            'actor',
            fn () => $this->service->createCustomCategory(
                (string) $schoolA->id,
                'pastoral_support',
                'Pastoral Support',
                null,
                90,
                (string) $foreignActor->id
            )
        );

        foreach (
            [
                ['active' => false],
                ['is_deleted' => true],
                ['suspended_at' => now()],
            ] as $state
        ) {
            $actor = $this->user($schoolA);

            DB::table('users')
                ->where('id', $actor->id)
                ->update($state);

            $this->expectValidationField(
                'actor',
                fn () => $this->service->createCustomCategory(
                    (string) $schoolA->id,
                    'custom_'.Str::lower(Str::random(8)),
                    'Custom Category',
                    null,
                    90,
                    (string) $actor->id
                )
            );
        }
    }

    public function test_deactivates_category_with_server_owned_terminal_lifecycle_evidence(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $category = $this->service->createCustomCategory(
            (string) $school->id,
            'pastoral_support',
            'Pastoral Support',
            null,
            90,
            (string) $actor->id
        );

        $deactivated = $this->service->deactivateCategory(
            (string) $school->id,
            (string) $category->id,
            (string) $actor->id
        );

        $this->assertFalse($deactivated->active);

        $this->assertSame(
            (string) $actor->id,
            (string) $deactivated->deactivated_by
        );

        $this->assertNotNull(
            $deactivated->deactivated_at
        );

        $this->assertDatabaseHas(
            'teacher_duty_occurrence_categories',
            [
                'id' => $category->id,
                'school_id' => $school->id,
                'active' => false,
                'deactivated_by' => $actor->id,
            ]
        );
    }

    public function test_category_deactivation_is_terminal(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $category = $this->service->createCustomCategory(
            (string) $school->id,
            'pastoral_support',
            'Pastoral Support',
            null,
            90,
            (string) $actor->id
        );

        $this->service->deactivateCategory(
            (string) $school->id,
            (string) $category->id,
            (string) $actor->id
        );

        $this->expectValidationField(
            'occurrence_category_id',
            fn () => $this->service->deactivateCategory(
                (string) $school->id,
                (string) $category->id,
                (string) $actor->id
            )
        );
    }

    public function test_category_deactivator_must_be_eligible_same_school_user(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $actorA = $this->user($schoolA);
        $foreignActor = $this->user($schoolB);

        $category = $this->service->createCustomCategory(
            (string) $schoolA->id,
            'pastoral_support',
            'Pastoral Support',
            null,
            90,
            (string) $actorA->id
        );

        $this->expectValidationField(
            'actor',
            fn () => $this->service->deactivateCategory(
                (string) $schoolA->id,
                (string) $category->id,
                (string) $foreignActor->id
            )
        );

        foreach (
            [
                ['active' => false],
                ['is_deleted' => true],
                ['suspended_at' => now()],
            ] as $state
        ) {
            $actor = $this->user($schoolA);

            DB::table('users')
                ->where('id', $actor->id)
                ->update($state);

            $this->expectValidationField(
                'actor',
                fn () => $this->service->deactivateCategory(
                    (string) $schoolA->id,
                    (string) $category->id,
                    (string) $actor->id
                )
            );
        }
    }

    public function test_category_deactivation_preserves_existing_occurrence_history(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $period = $this->period(
            $school,
            $actor
        );

        $category = $this->service->createCustomCategory(
            (string) $school->id,
            'pastoral_support',
            'Pastoral Support',
            null,
            90,
            (string) $actor->id
        );

        $occurrence = $this->service->recordOccurrence(
            (string) $school->id,
            (string) $period->id,
            (string) $category->id,
            '2026-09-09',
            null,
            'Historical occurrence.',
            (string) $actor->id
        );

        $this->service->deactivateCategory(
            (string) $school->id,
            (string) $category->id,
            (string) $actor->id
        );

        $this->assertDatabaseHas(
            'teacher_duty_occurrences',
            [
                'id' => $occurrence->id,
                'school_id' => $school->id,
                'occurrence_category_id' => $category->id,
                'description' => 'Historical occurrence.',
            ]
        );
    }

    private function period(
        School $school,
        User $actor
    ): object {
        return $this->roster->createPeriod(
            (string) $school->id,
            '2026-09-07',
            '2026-09-11',
            null,
            (string) $actor->id
        );
    }

    private function canonicalCategoryId(
        School $school,
        string $code
    ): string {
        $this->provisioner->provision($school);

        return (string) DB::table(
            'teacher_duty_occurrence_categories'
        )
            ->where('school_id', $school->id)
            ->where('code', $code)
            ->where('is_canonical', true)
            ->value('id');
    }

    private function school(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Occurrence Service '.Str::upper(
                Str::random(8)
            ),
            'school_code' => 'OCS-'.Str::upper(
                Str::random(8)
            ),
            'short_name' => 'OCS',
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

    private function user(School $school): User
    {
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'role_name' => 'Occurrence Service '.Str::upper(
                Str::random(8)
            ),
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Teacher',
            'last_name' => 'Duty',
            'username' => 'occurrence_service_'.Str::lower(
                Str::random(10)
            ),
            'email' => Str::lower(
                Str::random(10)
            ).'@example.test',
            'password_hash' => 'test-password-hash',
            'active' => true,
            'is_deleted' => false,
            'first_login' => false,
        ]);
    }

    private function expectValidationField(
        string $field,
        callable $callback
    ): void {
        try {
            $callback();

            $this->fail(
                "Expected validation error for {$field}."
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                $field,
                $exception->errors()
            );
        }
    }
}
