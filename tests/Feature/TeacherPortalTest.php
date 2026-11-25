<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TeacherPortal\TeacherPortalAccessService;
use App\Services\TeacherPortal\TeacherPortalMobileService;
use App\Services\TeacherPortal\TeacherPortalService;
use App\Services\Teaching\TeacherAssignmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\EducationLevelBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\LearningAreaAllocationBuilder;
use Tests\Support\Database\LearningAreaBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TeacherBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class TeacherPortalTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $otherSchool;

    private object $user;

    private object $otherUser;

    private object $teacher;

    private object $assignment;

    private object $learner;

    private object $unlinkedLearner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(
            Carbon::parse('2026-08-01 12:00:00')
        );

        $this->school = SchoolBuilder::create();
        $this->otherSchool = SchoolBuilder::create();

        $role = RoleBuilder::create([
            'role_name' => 'Teacher',
        ]);

        $this->user = UserBuilder::create(
            $this->school,
            $role
        );

        $this->otherUser = UserBuilder::create(
            $this->school,
            $role
        );

        $this->teacher = TeacherBuilder::create(
            $this->school,
            $this->user
        );

        $educationLevel = EducationLevelBuilder::create([
            'level_name' => 'Junior School '.Str::random(8),
            'level_order' => 3,
        ]);

        $grade = GradeBuilder::create(
            $this->school,
            $educationLevel,
            [
                'grade_name' => 'Grade 9',
                'grade_order' => 9,
            ]
        );

        $stream = StreamBuilder::create(
            $this->school,
            $grade,
            [
                'stream_name' => 'Grade 9 Kiswahili',
            ]
        );

        $learningArea = LearningAreaBuilder::create([
            'learning_area_name' => 'Kiswahili',
            'short_name' => 'KIS',
        ]);

        LearningAreaAllocationBuilder::create(
            $this->school,
            $grade,
            $learningArea,
            [
                'lessons_per_week' => 5,
            ]
        );

        $academicYear = AcademicYearBuilder::create(
            $this->school,
            [
                'year_name' => '2026',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'active' => true,
            ]
        );

        $term = TermBuilder::create(
            $this->school,
            $academicYear,
            [
                'term_name' => 'Term 2',
                'start_date' => '2026-05-01',
                'end_date' => '2026-08-31',
                'active' => true,
            ]
        );

        $this->assignment = app(
            TeacherAssignmentService::class
        )->create(
            [
                'teacher_id' => $this->teacher->id,
                'learning_area_id' => $learningArea->id,
                'grade_id' => $grade->id,
                'stream_id' => $stream->id,
                'academic_year_id' => $academicYear->id,
                'term_id' => $term->id,
                'lessons_per_week' => 5,
            ],
            $this->school->id
        );

        $this->learner = LearnerBuilder::create(
            $this->school,
            $grade,
            $stream,
            [
                'first_name' => 'Amina',
                'last_name' => 'Mwanafunzi',
            ]
        );

        $otherGrade = GradeBuilder::create(
            $this->otherSchool,
            $educationLevel,
            [
                'grade_name' => 'Other Grade 9',
                'grade_order' => 9,
            ]
        );

        $otherStream = StreamBuilder::create(
            $this->otherSchool,
            $otherGrade
        );

        $this->unlinkedLearner = LearnerBuilder::create(
            $this->otherSchool,
            $otherGrade,
            $otherStream,
            [
                'first_name' => 'Other',
                'last_name' => 'Learner',
            ]
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_teacher_resolution_assignments_classes_and_learners(): void
    {
        $access = app(
            TeacherPortalAccessService::class
        );

        $this->assertSame(
            $this->teacher->id,
            $access->teacher($this->user())->id
        );

        $this->assertCount(
            1,
            $access->assignments($this->user())
        );

        $this->assertCount(
            1,
            $access->learners($this->user())
        );

        $this->assertSame(
            $this->learner->id,
            $access->learners($this->user())->first()->id
        );
    }

    public function test_idor_and_cross_school_learner_access_is_rejected(): void
    {
        $this->expectException(
            AuthorizationException::class
        );

        app(TeacherPortalAccessService::class)
            ->requireLearner(
                $this->user(),
                $this->unlinkedLearner->id
            );
    }

    public function test_dashboard_preferences_are_configurable(): void
    {
        $service = app(
            TeacherPortalService::class
        );

        $preferences = $service->updatePreferences(
            $this->user(),
            [
                'show_todays_timetable' => false,
                'show_pending_lesson_plans' => false,
                'show_curriculum_coverage' => false,
                'show_notifications' => false,
                'show_announcements' => false,
                'show_attendance_summary' => false,
                'show_assessment_summary' => false,
                'show_performance_analytics' => false,
            ]
        );

        $this->assertFalse(
            $preferences->show_todays_timetable
        );

        $dashboard = $service->dashboard(
            $this->user()
        );

        $this->assertArrayNotHasKey(
            'todays_timetable',
            $dashboard
        );

        $this->assertArrayNotHasKey(
            'performance_analytics',
            $dashboard
        );

        $this->assertSame(
            $this->teacher->id,
            $dashboard['preferences']->teacher_id
        );
    }

    public function test_notifications_and_announcements_are_scoped(): void
    {
        DB::table('notifications')->insert([
            [
                'id' => (string) Str::uuid(),
                'school_id' => $this->school->id,
                'user_id' => $this->user->id,
                'title' => 'My Notification',
                'message' => 'Ujumbe wa mwalimu.',
                'is_read' => false,
                'created_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'school_id' => $this->school->id,
                'user_id' => $this->otherUser->id,
                'title' => 'Other Notification',
                'message' => 'Ujumbe wa mtumiaji mwingine.',
                'is_read' => false,
                'created_at' => now(),
            ],
        ]);

        $announcementId = (string) Str::uuid();

        DB::table('communications')->insert([
            'id' => $announcementId,
            'school_id' => $this->school->id,
            'sender_user_id' => $this->user->id,
            'communication_type' => 'announcement',
            'category' => 'general',
            'priority' => 'normal',
            'subject' => 'Tangazo la Walimu',
            'body' => 'Walimu wote wakumbushwe kuhusu maandalizi ya masomo.',
            'status' => 'sent',
            'requires_approval' => false,
            'risk_level' => 'low',
            'recipient_count' => 1,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(
            'communication_recipient_snapshots'
        )->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $this->school->id,
            'communication_id' => $announcementId,
            'user_id' => $this->user->id,
            'audience_type' => 'teacher',
            'resolved_at' => now(),
        ]);

        $otherAnnouncementId = (string) Str::uuid();

        DB::table('communications')->insert([
            'id' => $otherAnnouncementId,
            'school_id' => $this->school->id,
            'sender_user_id' => $this->user->id,
            'communication_type' => 'announcement',
            'category' => 'general',
            'priority' => 'normal',
            'subject' => 'Tangazo la Mtumiaji Mwingine',
            'body' => 'Tangazo hili halijatumwa kwa mwalimu huyu.',
            'status' => 'sent',
            'requires_approval' => false,
            'risk_level' => 'low',
            'recipient_count' => 1,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(
            'communication_recipient_snapshots'
        )->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $this->school->id,
            'communication_id' => $otherAnnouncementId,
            'user_id' => $this->otherUser->id,
            'audience_type' => 'teacher',
            'resolved_at' => now(),
        ]);

        $service = app(
            TeacherPortalService::class
        );

        $this->assertSame(
            1,
            $service->notifications(
                $this->user()
            )->total()
        );

        $announcements = $service->announcements(
            $this->user()
        );

        $this->assertCount(
            1,
            $announcements
        );

        $this->assertSame(
            'Tangazo la Walimu',
            $announcements->first()->subject
        );
    }

    public function test_assignment_scoped_records_exclude_unassigned_classes_and_schools(): void
    {
        $grade = DB::table('grades')->where('id', $this->assignment->grade_id)->first();
        $otherStream = StreamBuilder::create($this->school, $grade);
        LearnerBuilder::create($this->school, $grade, $otherStream);

        $access = app(TeacherPortalAccessService::class);
        $mobile = app(TeacherPortalMobileService::class);
        $schemeId = $this->createAssignmentScheme();

        $this->assertSame([$this->learner->id], $access->learners($this->user())->pluck('id')->all());
        $this->assertSame([$this->learner->id], $mobile->learners($this->user())->getCollection()->pluck('id')->all());
        $this->assertSame([$schemeId], $mobile->schemes($this->user())->pluck('id')->all());

        $role = $this->user()->role;
        $foreignUser = UserBuilder::create($this->otherSchool, $role);
        TeacherBuilder::create($this->otherSchool, $foreignUser);
        $foreignTeacher = User::with('role')->findOrFail($foreignUser->id);

        $this->assertEmpty($access->learners($foreignTeacher));
        $this->assertSame(0, $mobile->learners($foreignTeacher)->total());
        $this->assertFalse($mobile->schemes($foreignTeacher)->exists());
    }

    public function test_teacher_without_assignments_has_no_assignment_scoped_records(): void
    {
        TeacherBuilder::create($this->school, $this->otherUser);
        $teacher = User::with('role')->findOrFail($this->otherUser->id);
        $this->createAssignmentScheme();

        $this->assertEmpty(app(TeacherPortalAccessService::class)->learners($teacher));
        $this->assertSame(0, app(TeacherPortalMobileService::class)->learners($teacher)->total());
        $this->assertFalse(app(TeacherPortalMobileService::class)->schemes($teacher)->exists());
    }

    public function test_upcoming_assignment_is_visible_without_granting_assignment_scoped_records(): void
    {
        $this->createAssignmentScheme();
        Carbon::setTestNow(Carbon::parse('2026-04-01 12:00:00'));

        $access = app(TeacherPortalAccessService::class);
        $mobile = app(TeacherPortalMobileService::class);
        $teacher = $this->user();

        $this->assertEmpty($mobile->assignments($teacher));
        $upcoming = $mobile->assignments($teacher, includeUpcoming: true)->sole();
        $this->assertSame($this->assignment->id, $upcoming->id);
        $this->assertSame('upcoming', $upcoming->assignment_status);
        $this->assertEmpty($access->learners($teacher));
        $this->assertSame(0, $mobile->learners($teacher)->total());
        $this->assertFalse($mobile->schemes($teacher)->exists());
    }

    private function createAssignmentScheme(): string
    {
        $id = (string) Str::uuid();
        DB::table('schemes_of_work')->insert([
            'id' => $id,
            'school_id' => $this->school->id,
            'learning_area_id' => $this->assignment->learning_area_id,
            'grade_id' => $this->assignment->grade_id,
            'academic_year_id' => $this->assignment->academic_year_id,
            'term_id' => $this->assignment->term_id,
            'title' => 'Synthetic Kiswahili scheme',
            'active' => true,
            'is_deleted' => false,
        ]);

        return $id;
    }

    private function user(): User
    {
        return User::with('role')
            ->findOrFail($this->user->id);
    }
}
