<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearningAreaAllocationBuilder;
use Tests\Support\Database\LearningAreaBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TeacherBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class TeacherAssignmentTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $role;

    private object $teacher;

    private object $learningArea;

    private object $grade;

    private object $stream;

    private object $academicYear;

    private object $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $this->school = SchoolBuilder::create();

        $this->role = RoleBuilder::create();

        $user = UserBuilder::create(
            $this->school,
            $this->role
        );

        $this->teacher = TeacherBuilder::create(
            $this->school,
            $user
        );

        $this->learningArea = LearningAreaBuilder::create([
            'learning_area_name' => 'Mathematics',
        ]);

        $this->grade = GradeBuilder::create(
            $this->school
        );

        $this->stream = StreamBuilder::create(
            $this->school,
            $this->grade
        );

        $this->academicYear = AcademicYearBuilder::create(
            $this->school
        );

        $this->term = TermBuilder::create(
            $this->school,
            $this->academicYear
        );

        LearningAreaAllocationBuilder::create(
            $this->school,
            $this->grade,
            $this->learningArea,
            [
                'lessons_per_week' => 5,
            ]
        );
    }

    public function test_it_creates_a_valid_teacher_assignment(): void
    {
        $response = $this->postJson(
            '/api/teacher-assignments',
            $this->payload()
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'success',
                true
            )
            ->assertJsonPath(
                'data.school_id',
                $this->school->id
            )
            ->assertJsonPath(
                'data.teacher_id',
                $this->teacher->id
            );

        $this->assertDatabaseHas(
            'teacher_assignments',
            [
                'teacher_id' => $this->teacher->id,
                'is_deleted' => false,
            ]
        );
    }

    public function test_it_rejects_a_duplicate_assignment(): void
    {
        $this->postJson(
            '/api/teacher-assignments',
            $this->payload()
        )->assertCreated();

        $this->postJson(
            '/api/teacher-assignments',
            $this->payload()
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'assignment'
            );
    }

    public function test_it_enforces_one_class_teacher_per_stream_and_term(): void
    {
        $this->postJson(
            '/api/teacher-assignments',
            $this->payload([
                'is_class_teacher' => true,
            ])
        )->assertCreated();

        $secondUser = UserBuilder::create(
            $this->school,
            $this->role
        );

        $secondTeacher = TeacherBuilder::create(
            $this->school,
            $secondUser
        );

        $this->postJson(
            '/api/teacher-assignments',
            $this->payload([
                'teacher_id' => $secondTeacher->id,
                'is_class_teacher' => true,
            ])
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'is_class_teacher'
            );
    }

    public function test_academic_acceptance_authenticated_assignment_uses_own_school(): void
    {
        $token = $this->academicAcceptanceToken();
        $payload = $this->payload();
        unset($payload['school_id']);

        $this->withToken($token)
            ->postJson('/api/teacher-assignments', $payload)
            ->assertCreated()
            ->assertJsonPath('data.school_id', $this->school->id)
            ->assertJsonPath('data.teacher_id', $this->teacher->id);

        $this->assertDatabaseHas('teacher_assignments', [
            'school_id' => $this->school->id,
            'teacher_id' => $this->teacher->id,
            'grade_id' => $this->grade->id,
            'stream_id' => $this->stream->id,
            'academic_year_id' => $this->academicYear->id,
            'term_id' => $this->term->id,
            'learning_area_id' => $this->learningArea->id,
            'is_deleted' => false,
        ]);
    }

    public function test_academic_acceptance_authenticated_assignment_rejects_foreign_teacher(): void
    {
        $foreignSchool = SchoolBuilder::create();
        $foreignUser = UserBuilder::create($foreignSchool, $this->role);
        $foreignTeacher = TeacherBuilder::create($foreignSchool, $foreignUser);

        $token = $this->academicAcceptanceToken();
        $payload = $this->payload([
            'teacher_id' => $foreignTeacher->id,
        ]);
        unset($payload['school_id']);

        $before = DB::table('teacher_assignments')
            ->orderBy('id')->get()->toJson();

        $this->withToken($token)
            ->postJson('/api/teacher-assignments', $payload)
            ->assertUnprocessable();

        $this->assertSame(
            $before,
            DB::table('teacher_assignments')
                ->orderBy('id')->get()->toJson()
        );
    }

    public function test_academic_acceptance_school_cannot_modify_shared_catalogue(): void
    {
        $token = $this->academicAcceptanceToken();
        $before = (array) DB::table('learning_areas')
            ->where('id', $this->learningArea->id)->first();

        $this->withToken($token)
            ->putJson('/api/learning-areas/'.$this->learningArea->id, [
                'learning_area_name' => 'Changed by one school',
            ])
            ->assertForbidden();

        $this->assertSame(
            $before,
            (array) DB::table('learning_areas')
                ->where('id', $this->learningArea->id)->first()
        );
    }

    public function test_academic_acceptance_allocation_rejects_foreign_grade(): void
    {
        $foreignSchool = SchoolBuilder::create();
        $foreignGrade = GradeBuilder::create($foreignSchool);
        $token = $this->academicAcceptanceToken();

        $before = DB::table('learning_area_allocations')
            ->orderBy('id')->get()->toJson();

        $this->withToken($token)
            ->postJson('/api/learning-area-allocations', [
                'grade_id' => $foreignGrade->id,
                'learning_area_id' => $this->learningArea->id,
                'lessons_per_week' => 5,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('grade_id');

        $this->assertSame(
            $before,
            DB::table('learning_area_allocations')
                ->orderBy('id')->get()->toJson()
        );
    }

    public function test_academic_acceptance_foreign_allocation_is_inaccessible(): void
    {
        $foreignSchool = SchoolBuilder::create();
        $foreignGrade = GradeBuilder::create($foreignSchool);
        $foreign = LearningAreaAllocationBuilder::create(
            $foreignSchool, $foreignGrade, $this->learningArea
        );
        $token = $this->academicAcceptanceToken();
        $before = (array) DB::table('learning_area_allocations')
            ->where('id', $foreign->id)->first();
        $url = '/api/learning-area-allocations/'.$foreign->id;

        $response = $this->withToken($token)
            ->getJson('/api/learning-area-allocations')->assertOk();
        $this->assertStringNotContainsString($foreign->id, $response->getContent());

        $this->withToken($token)->getJson($url)->assertNotFound();
        $this->withToken($token)->putJson($url, [
            'lessons_per_week' => 10,
        ])->assertNotFound();
        $this->withToken($token)->deleteJson($url)->assertNotFound();

        $this->assertSame(
            $before,
            (array) DB::table('learning_area_allocations')
                ->where('id', $foreign->id)->first()
        );
    }

    public function test_academic_acceptance_own_allocation_lifecycle(): void
    {
        $area = LearningAreaBuilder::create();
        $token = $this->academicAcceptanceToken();

        $created = $this->withToken($token)
            ->postJson('/api/learning-area-allocations', [
                'grade_id' => $this->grade->id,
                'learning_area_id' => $area->id,
                'lessons_per_week' => 4,
            ])->assertCreated();

        $id = $created->json('data.id');
        $this->assertNotEmpty($id);
        $url = '/api/learning-area-allocations/'.$id;

        $this->assertDatabaseHas('learning_area_allocations', [
            'id' => $id,
            'school_id' => $this->school->id,
            'grade_id' => $this->grade->id,
            'learning_area_id' => $area->id,
        ]);

        $this->withToken($token)->getJson($url)->assertOk();
        $this->withToken($token)->putJson($url, [
            'lessons_per_week' => 6,
        ])->assertOk();

        $this->assertDatabaseHas('learning_area_allocations', [
            'id' => $id, 'lessons_per_week' => 6,
        ]);

        $this->withToken($token)->deleteJson($url)->assertOk();
        $this->assertDatabaseMissing('learning_area_allocations', ['id' => $id]);
    }

    public function test_academic_acceptance_allocation_update_rejects_invalid_lesson_count(): void
    {
        $token = $this->academicAcceptanceToken();
        $allocation = DB::table('learning_area_allocations')
            ->where('school_id', $this->school->id)->first();
        $this->assertNotNull($allocation);
        $before = (array) $allocation;

        $this->withToken($token)
            ->putJson('/api/learning-area-allocations/'.$allocation->id, [
                'lessons_per_week' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lessons_per_week');

        $this->assertSame(
            $before,
            (array) DB::table('learning_area_allocations')
                ->where('id', $allocation->id)->first()
        );
    }

    public function test_academic_acceptance_catalogue_reads_exclude_foreign_allocations(): void
    {
        $foreignSchool = SchoolBuilder::create();
        $foreignGrade = GradeBuilder::create($foreignSchool);
        $foreign = LearningAreaAllocationBuilder::create(
            $foreignSchool, $foreignGrade, $this->learningArea
        );

        $own = DB::table('learning_area_allocations')
            ->where('school_id', $this->school->id)->first();
        $this->assertNotNull($own);

        $token = $this->academicAcceptanceToken();

        foreach ([
            '/api/learning-areas',
            '/api/learning-areas/'.$this->learningArea->id,
        ] as $url) {
            $response = $this->withToken($token)->getJson($url)->assertOk();
            $payload = $response->getContent();

            $this->assertStringContainsString($this->learningArea->id, $payload);
            $this->assertStringContainsString($own->id, $payload);
            $this->assertStringNotContainsString($foreign->id, $payload);
            $this->assertStringNotContainsString($foreignSchool->id, $payload);
        }

        $this->withToken($token)
            ->getJson('/api/learning-areas/'.Str::uuid())
            ->assertNotFound();
    }

    private function academicAcceptanceToken(): string
    {
        $this->withMiddleware();
        config(['jwt.secret' => str_repeat('academic-acceptance-test-', 3)]);

        DB::table('schools')
            ->where('id', $this->school->id)
            ->update(['lifecycle_state' => 'active']);

        $permissionName = config('module_permissions.teacher-assignments');
        $this->assertIsString($permissionName);

        $permissionId = DB::table('permissions')
            ->where('permission_name', $permissionName)->value('id');
        $this->assertNotNull($permissionId);

        DB::table('role_permissions')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'role_id' => $this->role->id,
            'permission_id' => $permissionId,
            'created_at' => now(),
        ]);

        $user = User::withoutGlobalScopes()
            ->where('school_id', $this->school->id)
            ->where('role_id', $this->role->id)
            ->firstOrFail();

        return JWTAuth::fromUser($user);
    }

    private function payload(
        array $overrides = []
    ): array {
        return [
            ...[
                'school_id' => $this->school->id,
                'teacher_id' => $this->teacher->id,
                'learning_area_id' => $this->learningArea->id,
                'grade_id' => $this->grade->id,
                'stream_id' => $this->stream->id,
                'academic_year_id' => $this->academicYear->id,
                'term_id' => $this->term->id,
                'lessons_per_week' => 5,
            ],
            ...$overrides,
        ];
    }
}
