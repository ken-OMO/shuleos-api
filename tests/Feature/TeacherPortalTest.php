<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TeacherPortal\TeacherPortalAccessService;
use App\Services\TeacherPortal\TeacherPortalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherPortalTest extends TestCase
{
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['teacher_dashboard_preferences', 'notifications', 'broadcasts', 'curriculum_coverage', 'records_of_work', 'lesson_notes', 'lesson_plans', 'teacher_assignments', 'learners', 'teachers', 'learning_areas', 'streams', 'grades', 'terms', 'academic_years', 'user_roles', 'users', 'roles', 'schools'] as $t) {
            Schema::dropIfExists($t);
        }Schema::create('schools', fn (Blueprint $t) => $t->uuid('id')->primary());
        Schema::create('roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('role_name');
        });
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('role_id');
            $t->boolean('active');
            $t->timestamps();
        });
        Schema::create('user_roles', function (Blueprint $t) {
            $t->uuid('user_id');
            $t->uuid('role_id');
        });
        Schema::create('teachers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('user_id');
            $t->string('staff_no')->nullable();
            $t->boolean('active');
            $t->boolean('is_deleted');
            $t->timestamps();
        });
        foreach (['academic_years', 'terms', 'grades'] as $n) {
            Schema::create($n, function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('school_id');
            });
        }Schema::create('streams', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('grade_id');
        });
        Schema::create('learning_areas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('learning_area_name');
        });
        Schema::create('teacher_assignments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('teacher_id');
            $t->uuid('learning_area_id');
            $t->uuid('grade_id');
            $t->uuid('stream_id');
            $t->uuid('academic_year_id');
            $t->uuid('term_id');
            $t->boolean('active');
            $t->boolean('is_deleted');
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('learners', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('grade_id');
            $t->uuid('stream_id');
            $t->boolean('active');
            $t->boolean('is_deleted');
            $t->timestamps();
        });
        Schema::create('teacher_dashboard_preferences', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('teacher_id')->unique();
            foreach (['show_todays_timetable', 'show_pending_lesson_plans', 'show_curriculum_coverage', 'show_notifications', 'show_announcements', 'show_attendance_summary', 'show_assessment_summary', 'show_performance_analytics'] as $f) {
                $t->boolean($f)->default(1);
            }$t->timestamps();
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('user_id');
            $t->string('title');
            $t->timestamp('created_at');
        });
        Schema::create('broadcasts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->string('title');
            $t->string('target_group')->nullable();
            $t->string('status');
            $t->timestamp('sent_at')->nullable();
        });
        foreach (['school', 'other', 'role', 'user', 'teacher', 'other_teacher', 'year', 'term', 'grade', 'stream', 'area', 'assignment', 'learner', 'unlinked'] as $k) {
            $this->id[$k] = (string) Str::uuid();
        }DB::table('schools')->insert([['id' => $this->id['school']], ['id' => $this->id['other']]]);
        DB::table('roles')->insert(['id' => $this->id['role'], 'role_name' => 'Teacher']);
        DB::table('users')->insert(['id' => $this->id['user'], 'school_id' => $this->id['school'], 'role_id' => $this->id['role'], 'active' => 1]);
        DB::table('teachers')->insert([['id' => $this->id['teacher'], 'school_id' => $this->id['school'], 'user_id' => $this->id['user'], 'staff_no' => 'T1', 'active' => 1, 'is_deleted' => 0], ['id' => $this->id['other_teacher'], 'school_id' => $this->id['other'], 'user_id' => (string) Str::uuid(), 'staff_no' => null, 'active' => 1, 'is_deleted' => 0]]);
        DB::table('academic_years')->insert(['id' => $this->id['year'], 'school_id' => $this->id['school']]);
        DB::table('terms')->insert(['id' => $this->id['term'], 'school_id' => $this->id['school']]);
        DB::table('grades')->insert(['id' => $this->id['grade'], 'school_id' => $this->id['school']]);
        DB::table('streams')->insert(['id' => $this->id['stream'], 'school_id' => $this->id['school'], 'grade_id' => $this->id['grade']]);
        DB::table('learning_areas')->insert(['id' => $this->id['area'], 'learning_area_name' => 'Math']);
        DB::table('teacher_assignments')->insert(['id' => $this->id['assignment'], 'school_id' => $this->id['school'], 'teacher_id' => $this->id['teacher'], 'learning_area_id' => $this->id['area'], 'grade_id' => $this->id['grade'], 'stream_id' => $this->id['stream'], 'academic_year_id' => $this->id['year'], 'term_id' => $this->id['term'], 'active' => 1, 'is_deleted' => 0]);
        DB::table('learners')->insert([['id' => $this->id['learner'], 'school_id' => $this->id['school'], 'grade_id' => $this->id['grade'], 'stream_id' => $this->id['stream'], 'active' => 1, 'is_deleted' => 0], ['id' => $this->id['unlinked'], 'school_id' => $this->id['other'], 'grade_id' => $this->id['grade'], 'stream_id' => $this->id['stream'], 'active' => 1, 'is_deleted' => 0]]);
    }

    private function u(): User
    {
        return User::with('role')->find($this->id['user']);
    }

    public function test_teacher_resolution_assignments_classes_and_learners(): void
    {
        $a = app(TeacherPortalAccessService::class);
        $this->assertSame($this->id['teacher'], $a->teacher($this->u())->id);
        $this->assertCount(1, $a->assignments($this->u()));
        $this->assertCount(1, $a->learners($this->u()));
    }

    public function test_idor_and_cross_school_learner_access_is_rejected(): void
    {
        $this->expectException(AuthorizationException::class);
        app(TeacherPortalAccessService::class)->requireLearner($this->u(), $this->id['unlinked']);
    }

    public function test_dashboard_preferences_are_configurable(): void
    {
        $s = app(TeacherPortalService::class);
        $p = $s->updatePreferences($this->u(), ['show_todays_timetable' => false, 'show_pending_lesson_plans' => false, 'show_curriculum_coverage' => false, 'show_notifications' => false, 'show_announcements' => false, 'show_attendance_summary' => false, 'show_assessment_summary' => false, 'show_performance_analytics' => false]);
        $this->assertFalse($p->show_todays_timetable);
        $d = $s->dashboard($this->u());
        $this->assertArrayNotHasKey('todays_timetable', $d);
        $this->assertArrayNotHasKey('performance_analytics', $d);
    }

    public function test_notifications_and_announcements_are_scoped(): void
    {
        DB::table('notifications')->insert([['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'user_id' => $this->id['user'], 'title' => 'Mine', 'created_at' => now()], ['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'user_id' => (string) Str::uuid(), 'title' => 'Other', 'created_at' => now()]]);
        DB::table('broadcasts')->insert([['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'title' => 'Sent', 'target_group' => 'teachers', 'status' => 'SENT', 'sent_at' => now()], ['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'title' => 'Draft', 'target_group' => 'teachers', 'status' => 'DRAFT', 'sent_at' => null]]);
        $s = app(TeacherPortalService::class);
        $this->assertSame(1, $s->notifications($this->u())->total());
        $this->assertCount(1, $s->announcements($this->u()));
    }
}
