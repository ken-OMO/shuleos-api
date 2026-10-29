<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Assessment\AssessmentTypeService;
use App\Services\Assessment\ExamLearningAreaService;
use App\Services\Assessment\ExamPaperService;
use App\Services\Assessment\ExamResultService;
use App\Services\Assessment\ExamService;
use App\Services\Assessment\MarkEntryPermissionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\LearningAreaBuilder;
use Tests\Support\Database\MarkEntryPermissionBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class ExamResultTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $exam;

    private object $learningArea;

    private object $paper;

    private object $learner;

    private object $user;

    private object $permission;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('exam_results', 'is_absent')) {
            $migration = require database_path(
                'migrations/2026_10_17_090000_add_exam_absence_fields.php'
            );
            $migration->up();
        }

        $this->school = SchoolBuilder::create();

        $role = RoleBuilder::create([
            'role_name' => 'Teacher',
        ]);

        $this->user = UserBuilder::create(
            $this->school,
            $role
        );

        $grade = GradeBuilder::create($this->school);
        $stream = StreamBuilder::create($this->school, $grade);

        $this->learner = LearnerBuilder::create(
            $this->school,
            $grade,
            $stream
        );

        $assessmentType = app(AssessmentTypeService::class)->create(
            ['assessment_type_name' => 'Formative'],
            $this->school->id
        );

        $academicYear = AcademicYearBuilder::create($this->school);
        $term = TermBuilder::create($this->school, $academicYear);

        $this->exam = app(ExamService::class)->create(
            [
                'exam_name' => 'Term Two Exam',
                'assessment_type_id' => $assessmentType->id,
                'academic_year_id' => $academicYear->id,
                'term_id' => $term->id,
                'start_date' => '2026-07-20',
                'end_date' => '2026-07-25',
            ],
            $this->school->id,
            $this->user->id
        );

        $this->learningArea = LearningAreaBuilder::create();

        $examLearningArea = app(
            ExamLearningAreaService::class
        )->create(
            [
                'exam_id' => $this->exam->id,
                'learning_area_id' => $this->learningArea->id,
                'number_of_papers' => 1,
                'total_marks' => 100,
            ],
            $this->school->id
        );

        $this->paper = app(ExamPaperService::class)->create(
            [
                'exam_learning_area_id' => $examLearningArea->id,
                'paper_name' => 'Paper 1',
                'paper_number' => 1,
                'max_marks' => 100,
            ],
            $this->school->id
        );

        app(ExamService::class)->transition(
            $this->exam,
            'published'
        );

        $this->exam = $this->exam->fresh();

        $this->permission = MarkEntryPermissionBuilder::create(
            $this->exam
        );
    }

    public function test_it_derives_exam_and_learning_area_from_the_paper(): void
    {
        $result = app(ExamResultService::class)->create(
            [
                'learner_id' => $this->learner->id,
                'paper_id' => $this->paper->id,
                'marks' => 78,
            ],
            $this->school->id,
            $this->user->id
        );

        $this->assertSame($this->exam->id, $result->exam_id);
        $this->assertSame(
            $this->learningArea->id,
            $result->learning_area_id
        );
        $this->assertSame('78.00', $result->marks);
    }

    public function test_it_rejects_marks_above_the_paper_maximum(): void
    {
        $this->expectException(ValidationException::class);

        app(ExamResultService::class)->create(
            [
                'learner_id' => $this->learner->id,
                'paper_id' => $this->paper->id,
                'marks' => 101,
            ],
            $this->school->id,
            $this->user->id
        );
    }

    public function test_it_rejects_duplicate_learner_paper_results(): void
    {
        $service = app(ExamResultService::class);

        $data = [
            'learner_id' => $this->learner->id,
            'paper_id' => $this->paper->id,
            'marks' => 70,
        ];

        $service->create(
            $data,
            $this->school->id,
            $this->user->id
        );

        $this->expectException(ValidationException::class);

        $service->create(
            $data,
            $this->school->id,
            $this->user->id
        );
    }

    public function test_it_rejects_cross_school_learners(): void
    {
        $otherSchool = SchoolBuilder::create();

        DB::table('learners')
            ->where('id', $this->learner->id)
            ->update([
                'school_id' => $otherSchool->id,
            ]);

        $this->expectException(ValidationException::class);

        app(ExamResultService::class)->create(
            [
                'learner_id' => $this->learner->id,
                'paper_id' => $this->paper->id,
                'marks' => 65,
            ],
            $this->school->id,
            $this->user->id
        );
    }

    public function test_permission_window_rejects_invalid_resulting_intervals(): void
    {
        $service = app(MarkEntryPermissionService::class);
        $stored = DB::table('mark_entry_permissions')
            ->where('id', $this->permission->id)->first();
        $before = (array) $stored;

        foreach ([
            ['opens_at' => $stored->closes_at],
            ['opens_at' => now()->addHours(2)->toDateTimeString()],
            ['closes_at' => $stored->opens_at],
            ['closes_at' => now()->subHours(2)->toDateTimeString()],
        ] as $values) {
            $this->assertPermissionValidationRejected(
                fn () => $service->update(
                    $this->school->id, $this->permission->id, $values
                ),
                'closes_at'
            );

            $this->assertSame($before, (array) DB::table('mark_entry_permissions')
                ->where('id', $this->permission->id)->first());
        }

        foreach ([
            ['opens_at' => '2026-07-20 10:00:00', 'closes_at' => '2026-07-20 10:00:00'],
            ['opens_at' => '2026-07-20 11:00:00', 'closes_at' => '2026-07-20 10:00:00'],
        ] as $window) {
            $this->assertPermissionValidationRejected(
                fn () => $service->create([
                    'exam_id' => $this->exam->id,
                    'role_name' => 'Window validation',
                    ...$window,
                ], $this->school->id),
                'closes_at'
            );
        }

        $this->assertSame(1, DB::table('mark_entry_permissions')
            ->where('exam_id', $this->exam->id)->count());
    }

    public function test_permission_window_accepts_nullable_boundaries(): void
    {
        $service = app(MarkEntryPermissionService::class);
        $before = DB::table('mark_entry_permissions')
            ->where('id', $this->permission->id)->first();

        $permission = $service->update(
            $this->school->id, $this->permission->id, ['opens_at' => null]
        );
        $this->assertNull($permission->opens_at);
        $this->assertSame(
            $before->closes_at,
            DB::table('mark_entry_permissions')
                ->where('id', $this->permission->id)->value('closes_at')
        );

        $permission = $service->update(
            $this->school->id, $this->permission->id,
            [
                'opens_at' => now()->addDay()->toDateTimeString(),
                'closes_at' => null,
            ]
        );
        $this->assertNull($permission->closes_at);
        $this->assertFalse($permission->isOpen());

        $permission = $service->update(
            $this->school->id, $this->permission->id,
            ['opens_at' => null, 'closes_at' => null]
        );
        $this->assertTrue($permission->isOpen());

        $permission = $service->update(
            $this->school->id, $this->permission->id, ['active' => false]
        );
        $this->assertFalse($permission->isOpen());

        $created = $service->create([
            'exam_id' => $this->exam->id,
            'role_name' => 'Unbounded role',
            'opens_at' => null,
            'closes_at' => null,
        ], $this->school->id);

        $this->assertTrue($created->isOpen());
    }

    public function test_permission_mutations_reject_another_schools_record(): void
    {
        $service = app(MarkEntryPermissionService::class);
        $foreignSchool = SchoolBuilder::create();
        $before = (array) DB::table('mark_entry_permissions')
            ->where('id', $this->permission->id)->first();

        foreach ([
            fn () => $service->update(
                $foreignSchool->id, $this->permission->id, ['active' => false]
            ),
            fn () => $service->revoke(
                $foreignSchool->id, $this->permission->id, null
            ),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected another school permission to be unavailable.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }

            $this->assertSame($before, (array) DB::table('mark_entry_permissions')
                ->where('id', $this->permission->id)->first());
        }
    }

    public function test_permission_revocation_blocks_reactivation_and_mark_entry(): void
    {
        $service = app(MarkEntryPermissionService::class);
        $service->revoke(
            $this->school->id, $this->permission->id, $this->user->id
        );

        $stored = DB::table('mark_entry_permissions')
            ->where('id', $this->permission->id)->first();
        $this->assertFalse((bool) $stored->active);
        $this->assertTrue((bool) $stored->is_deleted);
        $this->assertSame($this->user->id, $stored->deleted_by);
        $this->assertNotNull($stored->deleted_at);

        try {
            $service->update(
                $this->school->id, $this->permission->id, ['active' => true]
            );
            $this->fail('Expected revoked permission to be unavailable.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertPermissionValidationRejected(
            fn () => app(ExamResultService::class)->create([
                'learner_id' => $this->learner->id,
                'paper_id' => $this->paper->id,
                'marks' => 65,
            ], $this->school->id, $this->user->id),
            'permission'
        );

        $this->assertSame(0, DB::table('exam_results')
            ->where('exam_id', $this->exam->id)->count());
        $this->assertSame((array) $stored, (array) DB::table('mark_entry_permissions')
            ->where('id', $this->permission->id)->first());
    }

    public function test_closed_exam_permission_cannot_reopen_but_can_be_revoked(): void
    {
        $service = app(MarkEntryPermissionService::class);
        DB::table('mark_entry_permissions')
            ->where('id', $this->permission->id)->update(['active' => false]);

        app(ExamService::class)->transition($this->exam, 'closed');

        $this->assertPermissionValidationRejected(
            fn () => $service->update(
                $this->school->id, $this->permission->id, ['active' => true]
            ),
            'exam_id'
        );

        $service->revoke(
            $this->school->id, $this->permission->id, $this->user->id
        );

        $this->assertTrue((bool) DB::table('mark_entry_permissions')
            ->where('id', $this->permission->id)->value('is_deleted'));
    }

    private function assertPermissionValidationRejected(
        callable $operation,
        string $field
    ): void {
        try {
            $operation();
            $this->fail('Expected mark-entry permission validation rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_permission_partial_update_validates_stored_closing_time(): void
    {
        $this->withoutMiddleware();
        $user = User::withoutGlobalScopes()->findOrFail($this->user->id);
        Auth::setUser($user);

        $before = (array) DB::table('mark_entry_permissions')
            ->where('id', $this->permission->id)->first();

        $this->putJson(
            '/api/mark-entry-permissions/'.$this->permission->id,
            [
                'school_id' => $this->school->id,
                'opens_at' => now()->addHours(2)->toDateTimeString(),
            ]
        )->assertUnprocessable();

        $this->assertSame(
            $before,
            (array) DB::table('mark_entry_permissions')
                ->where('id', $this->permission->id)->first()
        );
    }

    public function test_it_rejects_results_when_the_permission_window_is_closed(): void
    {
        DB::table('mark_entry_permissions')
            ->where('id', $this->permission->id)
            ->update([
                'opens_at' => now()->subHours(2),
                'closes_at' => now()->subHour(),
            ]);

        $this->expectException(ValidationException::class);

        app(ExamResultService::class)->create(
            [
                'learner_id' => $this->learner->id,
                'paper_id' => $this->paper->id,
                'marks' => 65,
            ],
            $this->school->id,
            $this->user->id
        );
    }
}
