<?php

namespace Tests\Feature\TeacherDuty;

use App\Core\Security\File\SecureFileStorage;
use App\Models\Role;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Pdf\TeacherDutyWeeklyReportPdfService;
use App\Services\TeacherDuty\TeacherDutyRosterService;
use App\Services\TeacherDuty\TeacherDutyWeeklyReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\Database\TeacherBuilder;
use Tests\TestCase;

final class TeacherDutyWeeklyReportPdfTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $reporter;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);

        $this->school = $this->createSchool();
        $this->completeOperationalSetup($this->school);
        $this->createSettings($this->school);

        $this->reporter = $this->createUser($this->school);
        TeacherBuilder::create($this->school, $this->reporter);

        $this->grantPermission(
            $this->reporter,
            'submit_teacher_duty_reports'
        );
    }

    public function test_responsible_reporter_can_stream_weekly_report_as_pdf(): void
    {
        $period = app(TeacherDutyRosterService::class)
            ->createPeriod(
                (string) $this->school->id,
                '2026-09-07',
                '2026-09-11',
                null,
                (string) $this->reporter->id
            );

        $teacher = Teacher::query()
            ->withoutGlobalScopes()
            ->where('school_id', $this->school->id)
            ->where('user_id', $this->reporter->id)
            ->firstOrFail();

        app(TeacherDutyRosterService::class)
            ->assignTeacher(
                (string) $this->school->id,
                (string) $period->id,
                (string) $teacher->id,
                (string) $this->reporter->id
            );

        $report = app(TeacherDutyWeeklyReportService::class)
            ->openReport(
                (string) $this->school->id,
                (string) $period->id,
                (string) $this->reporter->id
            );

        $this->actingAsJwt($this->reporter);

        $response = $this->get(
            "/api/teacher-duty/weekly-reports/{$report->id}/pdf"
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith(
            '%PDF',
            $response->getContent()
        );
    }

    private function actingAsJwt(User $user): void
    {
        $token = JWTAuth::fromUser($user);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ]);
    }

    private function createSettings(School $school): void
    {
        DB::table('school_settings')->insert([
            'school_id' => $school->id,
            'teacher_duty_report_deadline_time' => '17:00:00',
            'teacher_duty_report_grace_minutes' => 120,
        ]);
    }

    public function test_responsible_reporter_can_download_weekly_report_as_pdf(): void
    {
        $period = app(TeacherDutyRosterService::class)->createPeriod(
            (string) $this->school->id,
            '2026-09-14',
            '2026-09-18',
            null,
            (string) $this->reporter->id
        );

        $teacher = Teacher::query()
            ->where('school_id', $this->school->id)
            ->where('user_id', $this->reporter->id)
            ->firstOrFail();

        app(TeacherDutyRosterService::class)->assignTeacher(
            (string) $this->school->id,
            (string) $period->id,
            (string) $teacher->id,
            (string) $this->reporter->id
        );

        $report = app(TeacherDutyWeeklyReportService::class)->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        $this->withToken(JWTAuth::fromUser($this->reporter));

        $response = $this->get(
            "/api/teacher-duty/weekly-reports/{$report->id}/pdf/download"
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        $contentDisposition = (string) $response->headers->get(
            'content-disposition'
        );

        $this->assertStringContainsString(
            'attachment;',
            $contentDisposition
        );

        $this->assertStringContainsString(
            'teacher-duty-weekly-report-'.$report->id.'.pdf',
            $contentDisposition
        );

        $this->assertStringStartsWith(
            '%PDF',
            $response->getContent()
        );
    }

    public function test_same_school_reviewer_can_stream_weekly_report_without_teacher_assignment(): void
    {
        $period = app(TeacherDutyRosterService::class)->createPeriod(
            (string) $this->school->id,
            '2026-09-21',
            '2026-09-25',
            null,
            (string) $this->reporter->id
        );

        $teacher = Teacher::query()
            ->where('school_id', $this->school->id)
            ->where('user_id', $this->reporter->id)
            ->firstOrFail();

        app(TeacherDutyRosterService::class)->assignTeacher(
            (string) $this->school->id,
            (string) $period->id,
            (string) $teacher->id,
            (string) $this->reporter->id
        );

        $report = app(TeacherDutyWeeklyReportService::class)->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        $reviewer = $this->createUser($this->school);

        $this->grantPermission(
            $reviewer,
            'review_teacher_duty_reports'
        );

        $this->assertDatabaseMissing(
            'teachers',
            [
                'school_id' => $this->school->id,
                'user_id' => $reviewer->id,
            ]
        );

        $this->withToken(JWTAuth::fromUser($reviewer));

        $response = $this->get(
            "/api/teacher-duty/weekly-reports/{$report->id}/pdf"
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith(
            '%PDF',
            $response->getContent()
        );
    }

    public function test_unrelated_same_school_user_cannot_stream_weekly_report_pdf(): void
    {
        $period = app(TeacherDutyRosterService::class)->createPeriod(
            (string) $this->school->id,
            '2026-09-28',
            '2026-10-02',
            null,
            (string) $this->reporter->id
        );

        $teacher = Teacher::query()
            ->where('school_id', $this->school->id)
            ->where('user_id', $this->reporter->id)
            ->firstOrFail();

        app(TeacherDutyRosterService::class)->assignTeacher(
            (string) $this->school->id,
            (string) $period->id,
            (string) $teacher->id,
            (string) $this->reporter->id
        );

        $report = app(TeacherDutyWeeklyReportService::class)->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        $unrelatedUser = $this->createUser($this->school);

        $this->assertDatabaseMissing(
            'teachers',
            [
                'school_id' => $this->school->id,
                'user_id' => $unrelatedUser->id,
            ]
        );

        $this->withToken(JWTAuth::fromUser($unrelatedUser));

        $response = $this->getJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/pdf"
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reviewer']);
    }

    public function test_cross_school_user_cannot_stream_weekly_report_pdf(): void
    {
        $period = app(TeacherDutyRosterService::class)->createPeriod(
            (string) $this->school->id,
            '2026-10-05',
            '2026-10-09',
            null,
            (string) $this->reporter->id
        );

        $teacher = Teacher::query()
            ->where('school_id', $this->school->id)
            ->where('user_id', $this->reporter->id)
            ->firstOrFail();

        app(TeacherDutyRosterService::class)->assignTeacher(
            (string) $this->school->id,
            (string) $period->id,
            (string) $teacher->id,
            (string) $this->reporter->id
        );

        $report = app(TeacherDutyWeeklyReportService::class)->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        $otherSchool = $this->createSchool();
        $this->completeOperationalSetup($otherSchool);

        $foreignReviewer = $this->createUser($otherSchool);

        $this->grantPermission(
            $foreignReviewer,
            'review_teacher_duty_reports'
        );

        $this->withToken(JWTAuth::fromUser($foreignReviewer));

        $response = $this->getJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/pdf"
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['report']);
    }

    private function createSchool(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Teacher Duty PDF '.Str::upper(Str::random(8)),
            'school_code' => 'TDP-'.Str::upper(Str::random(8)),
            'short_name' => 'TDP',
            'registration_number' => 'REG-'.Str::upper(Str::random(10)),
            'school_type' => 'Primary',
            'county' => 'Nairobi',
            'phone' => '+2547'.random_int(10000000, 99999999),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
            'active' => true,
        ]);
    }

    private function createUser(School $school): User
    {
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'role_name' => 'Teacher Duty PDF '.Str::upper(Str::random(8)),
            'description' => 'Teacher duty PDF test role',
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Teacher',
            'last_name' => 'Duty PDF',
            'username' => 'teacher_duty_pdf_'.
                Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(10)).
                '@example.test',
            'password_hash' => bcrypt('Password123!'),
            'active' => true,
            'first_login' => false,
        ]);
    }

    private function completeOperationalSetup(School $school): void
    {
        $academicYearId = (string) Str::uuid();
        $gradeId = (string) Str::uuid();

        DB::table('academic_years')->insert([
            'id' => $academicYearId,
            'school_id' => $school->id,
            'year_name' => 'Operational '.Str::upper(Str::random(8)),
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('terms')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'academic_year_id' => $academicYearId,
            'term_name' => 'Operational '.Str::upper(Str::random(6)),
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('grades')->insert([
            'id' => $gradeId,
            'school_id' => $school->id,
            'grade_name' => 'Grade 8',
            'grade_order' => 8,
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('streams')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'grade_id' => $gradeId,
            'stream_name' => 'North',
            'active' => true,
            'created_at' => now(),
        ]);
    }

    private function grantPermission(
        User $user,
        string $permissionName
    ): void {
        $permissionId = DB::table('permissions')
            ->where('permission_name', $permissionName)
            ->value('id');

        if (! $permissionId) {
            throw new \RuntimeException(
                "Required migrated permission [{$permissionName}] was not found."
            );
        }

        DB::table('role_permissions')->insertOrIgnore([
            'role_id' => $user->role_id,
            'permission_id' => $permissionId,
        ]);
    }

    public function test_pdf_generation_is_read_only_against_teacher_duty_lifecycle(): void
    {
        $period = app(TeacherDutyRosterService::class)->createPeriod(
            (string) $this->school->id,
            '2026-11-02',
            '2026-11-06',
            null,
            (string) $this->reporter->id
        );

        $teacher = Teacher::query()
            ->withoutGlobalScopes()
            ->where('school_id', $this->school->id)
            ->where('user_id', $this->reporter->id)
            ->firstOrFail();

        app(TeacherDutyRosterService::class)->assignTeacher(
            (string) $this->school->id,
            (string) $period->id,
            (string) $teacher->id,
            (string) $this->reporter->id
        );

        $report = app(TeacherDutyWeeklyReportService::class)->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        $before = DB::table('teacher_duty_weekly_reports')
            ->where('id', $report->id)
            ->first();

        $historyBefore = DB::table('teacher_duty_weekly_report_history')
            ->where('weekly_report_id', $report->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $this->actingAsJwt($this->reporter);

        $this->get(
            "/api/teacher-duty/weekly-reports/{$report->id}/pdf"
        )
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $after = DB::table('teacher_duty_weekly_reports')
            ->where('id', $report->id)
            ->first();

        $historyAfter = DB::table('teacher_duty_weekly_report_history')
            ->where('weekly_report_id', $report->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $this->assertEquals(
            (array) $before,
            (array) $after,
            'Generating a PDF must not mutate the weekly report.'
        );

        $this->assertSame(
            $historyBefore,
            $historyAfter,
            'Generating a PDF must not append or mutate lifecycle history.'
        );
    }

    public function test_pdf_contains_school_and_all_responsible_teacher_details(): void
    {
        $period = app(TeacherDutyRosterService::class)->createPeriod(
            (string) $this->school->id,
            '2026-10-26',
            '2026-10-30',
            null,
            (string) $this->reporter->id
        );

        $firstTeacher = Teacher::query()
            ->withoutGlobalScopes()
            ->where('school_id', $this->school->id)
            ->where('user_id', $this->reporter->id)
            ->firstOrFail();

        $firstTeacher->forceFill([
            'tsc_no' => 'TSC-100001',
        ])->save();

        $this->reporter->forceFill([
            'first_name' => 'Kennedy',
            'middle_name' => 'Otieno',
            'last_name' => 'Omondi',
        ])->save();

        app(TeacherDutyRosterService::class)->assignTeacher(
            (string) $this->school->id,
            (string) $period->id,
            (string) $firstTeacher->id,
            (string) $this->reporter->id
        );

        $secondUser = $this->createUser($this->school);

        $secondUser->forceFill([
            'first_name' => 'Grace',
            'middle_name' => null,
            'last_name' => 'Achieng',
        ])->save();

        $secondTeacher = TeacherBuilder::create(
            $this->school,
            $secondUser,
            [
                'tsc_no' => null,
            ]
        );

        app(TeacherDutyRosterService::class)->assignTeacher(
            (string) $this->school->id,
            (string) $period->id,
            (string) $secondTeacher->id,
            (string) $this->reporter->id
        );

        $report = app(TeacherDutyWeeklyReportService::class)->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        $service = app(TeacherDutyWeeklyReportPdfService::class);

        $method = new \ReflectionMethod(
            TeacherDutyWeeklyReportPdfService::class,
            'printData'
        );

        $method->setAccessible(true);

        $data = $method->invoke($service, $report);

        $this->assertSame(
            $this->school->school_name,
            $data['school_name']
        );

        $this->assertSame(
            'TEACHER DUTY WEEKLY REPORT',
            $data['title']
        );

        $this->assertSame([
            [
                'name' => 'Kennedy Otieno Omondi',
                'tsc_no' => 'TSC-100001',
            ],
            [
                'name' => 'Grace Achieng',
                'tsc_no' => "\u{2014}",
            ],
        ], $data['teachers']);
    }

    public function test_printable_layout_contains_required_teacher_duty_sections(): void
    {
        $view = file_get_contents(
            resource_path(
                'views/pdf/teacher-duty-weekly-report.blade.php'
            )
        );

        $this->assertIsString($view);

        $required = [
            'TEACHER DUTY WEEKLY REPORT',
            'School:',
            'Responsible Teacher',
            'TSC No.',
            'Academic Year:',
            'Term:',
            'Week:',
            'Period:',
            'Summary',
            'Highlights',
            'Challenges',
            'Recommendations',
            'Weekly Evidence',
            'Daily Reports',
            'Occurrences',
            'Submission &amp; Review',
            "Teacher's Signature:",
            'Date:',
            'School Stamp:',
            'Generated by ShuleOS',
        ];

        foreach ($required as $text) {
            $this->assertStringContainsString(
                $text,
                $view,
                "Missing printable section/marker: {$text}"
            );
        }

        $this->assertStringContainsString(
            '$printData[',
            $view
        );

        $this->assertStringContainsString(
            '$logo',
            $view
        );

        $this->assertStringContainsString(
            'content: counter(page)',
            $view
        );

        $this->assertStringNotContainsString(
            'counter(pages)',
            $view
        );
    }

    public function test_rendered_pdf_receives_authoritative_print_data(): void
    {
        $service = app(TeacherDutyWeeklyReportPdfService::class);

        $source = file_get_contents(
            app_path(
                'Services/Pdf/TeacherDutyWeeklyReportPdfService.php'
            )
        );

        $this->assertIsString($source);

        $this->assertStringContainsString(
            '$printData = $this->printData($report);',
            $source
        );

        $this->assertStringContainsString(
            "'printData' => ".'$printData',
            $source
        );

        $view = file_get_contents(
            resource_path(
                'views/pdf/teacher-duty-weekly-report.blade.php'
            )
        );

        $this->assertIsString($view);

        $this->assertStringContainsString(
            '$printData',
            $view
        );

        unset($service);
    }

    public function test_pdf_contains_current_submission_and_review_details(): void
    {
        $period = app(TeacherDutyRosterService::class)->createPeriod(
            (string) $this->school->id,
            '2026-11-16',
            '2026-11-20',
            null,
            (string) $this->reporter->id
        );

        $teacher = Teacher::query()
            ->withoutGlobalScopes()
            ->where('school_id', $this->school->id)
            ->where('user_id', $this->reporter->id)
            ->firstOrFail();

        app(TeacherDutyRosterService::class)->assignTeacher(
            (string) $this->school->id,
            (string) $period->id,
            (string) $teacher->id,
            (string) $this->reporter->id
        );

        $report = app(TeacherDutyWeeklyReportService::class)->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        app(TeacherDutyRosterService::class)->endPeriod(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        $submitted = app(TeacherDutyWeeklyReportService::class)->submitReport(
            (string) $this->school->id,
            (string) $report->id,
            (string) $this->reporter->id
        );

        $reviewer = $this->createUser($this->school);

        $this->grantPermission(
            $reviewer,
            'review_teacher_duty_reports'
        );

        $reviewed = app(TeacherDutyWeeklyReportService::class)->reviewReport(
            (string) $this->school->id,
            (string) $submitted->id,
            'approved',
            null,
            (string) $reviewer->id
        );

        $service = app(TeacherDutyWeeklyReportPdfService::class);

        $method = new \ReflectionMethod(
            TeacherDutyWeeklyReportPdfService::class,
            'printData'
        );

        $method->setAccessible(true);

        $data = $method->invoke($service, $reviewed);

        $submitterName = collect([
            $this->reporter->first_name,
            $this->reporter->middle_name,
            $this->reporter->last_name,
        ])
            ->filter(
                static fn ($part): bool => is_string($part)
                    && trim($part) !== ''
            )
            ->map(
                static fn (string $part): string => trim($part)
            )
            ->implode(' ');

        $reviewerName = collect([
            $reviewer->first_name,
            $reviewer->middle_name,
            $reviewer->last_name,
        ])
            ->filter(
                static fn ($part): bool => is_string($part)
                    && trim($part) !== ''
            )
            ->map(
                static fn (string $part): string => trim($part)
            )
            ->implode(' ');

        $this->assertSame(
            'approved',
            $data['workflow']['status']
        );

        $this->assertSame(
            $submitterName,
            $data['workflow']['submitted_by_name']
        );

        $this->assertSame(
            $submitted->submitted_at?->toISOString(),
            $data['workflow']['submitted_at']
        );

        $this->assertSame(
            $reviewerName,
            $data['workflow']['reviewed_by_name']
        );

        $this->assertSame(
            $reviewed->reviewed_at?->toISOString(),
            $data['workflow']['reviewed_at']
        );

        $this->assertNull(
            $data['workflow']['review_comment']
        );
    }

    public function test_pdf_uses_persisted_submission_evidence_snapshot(): void
    {
        $period = app(TeacherDutyRosterService::class)->createPeriod(
            (string) $this->school->id,
            '2026-11-09',
            '2026-11-13',
            null,
            (string) $this->reporter->id
        );

        $teacher = Teacher::query()
            ->withoutGlobalScopes()
            ->where('school_id', $this->school->id)
            ->where('user_id', $this->reporter->id)
            ->firstOrFail();

        app(TeacherDutyRosterService::class)->assignTeacher(
            (string) $this->school->id,
            (string) $period->id,
            (string) $teacher->id,
            (string) $this->reporter->id
        );

        $report = app(TeacherDutyWeeklyReportService::class)->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        app(TeacherDutyRosterService::class)->endPeriod(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        $submitted = app(TeacherDutyWeeklyReportService::class)->submitReport(
            (string) $this->school->id,
            (string) $report->id,
            (string) $this->reporter->id
        );

        $this->assertSame('submitted', $submitted->status);
        $this->assertIsArray($submitted->evidence_snapshot);
        $this->assertNotEmpty($submitted->evidence_snapshot);

        $persistedSnapshot = $submitted->evidence_snapshot;

        $service = app(TeacherDutyWeeklyReportPdfService::class);

        $method = new \ReflectionMethod(
            TeacherDutyWeeklyReportPdfService::class,
            'printData'
        );

        $method->setAccessible(true);

        $data = $method->invoke($service, $submitted);

        $this->assertSame(
            $persistedSnapshot,
            $data['evidence']
        );
    }

    public function test_pdf_contains_weekly_report_narrative(): void
    {
        $period = app(TeacherDutyRosterService::class)->createPeriod(
            (string) $this->school->id,
            '2026-11-02',
            '2026-11-06',
            null,
            (string) $this->reporter->id
        );

        $teacher = Teacher::query()
            ->withoutGlobalScopes()
            ->where('school_id', $this->school->id)
            ->where('user_id', $this->reporter->id)
            ->firstOrFail();

        app(TeacherDutyRosterService::class)->assignTeacher(
            (string) $this->school->id,
            (string) $period->id,
            (string) $teacher->id,
            (string) $this->reporter->id
        );

        $report = app(TeacherDutyWeeklyReportService::class)->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        $report->forceFill([
            'summary' => 'The school week was orderly and learning proceeded normally.',
            'highlights' => 'Morning assembly and sanitation checks were completed daily.',
            'challenges' => 'Late arrival was observed among a small number of learners.',
            'recommendations' => 'Continue monitoring punctuality at the main gate.',
        ])->save();

        $report->refresh();

        $service = app(TeacherDutyWeeklyReportPdfService::class);

        $method = new \ReflectionMethod(
            TeacherDutyWeeklyReportPdfService::class,
            'printData'
        );

        $method->setAccessible(true);

        $data = $method->invoke($service, $report);

        $this->assertSame(
            'The school week was orderly and learning proceeded normally.',
            $data['report']['summary']
        );

        $this->assertSame(
            'Morning assembly and sanitation checks were completed daily.',
            $data['report']['highlights']
        );

        $this->assertSame(
            'Late arrival was observed among a small number of learners.',
            $data['report']['challenges']
        );

        $this->assertSame(
            'Continue monitoring punctuality at the main gate.',
            $data['report']['recommendations']
        );
    }

    public function test_pdf_contains_authoritative_duty_period_and_academic_week_details(): void
    {
        $academicYear = DB::table('academic_years')
            ->where('school_id', $this->school->id)
            ->orderBy('created_at')
            ->first();

        $term = DB::table('terms')
            ->where('school_id', $this->school->id)
            ->orderBy('created_at')
            ->first();

        $this->assertNotNull($academicYear);
        $this->assertNotNull($term);

        $weekId = (string) Str::uuid();

        DB::table('academic_weeks')->insert([
            'id' => $weekId,
            'school_id' => $this->school->id,
            'academic_year_id' => $academicYear->id,
            'term_id' => $term->id,
            'week_number' => 7,
            'start_date' => '2026-10-26',
            'end_date' => '2026-10-30',
            'active' => true,
        ]);

        $period = app(TeacherDutyRosterService::class)->createPeriod(
            (string) $this->school->id,
            '2026-10-26',
            '2026-10-30',
            $weekId,
            (string) $this->reporter->id
        );

        $teacher = Teacher::query()
            ->withoutGlobalScopes()
            ->where('school_id', $this->school->id)
            ->where('user_id', $this->reporter->id)
            ->firstOrFail();

        app(TeacherDutyRosterService::class)->assignTeacher(
            (string) $this->school->id,
            (string) $period->id,
            (string) $teacher->id,
            (string) $this->reporter->id
        );

        $report = app(TeacherDutyWeeklyReportService::class)->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        $service = app(TeacherDutyWeeklyReportPdfService::class);

        $method = new \ReflectionMethod(
            TeacherDutyWeeklyReportPdfService::class,
            'printData'
        );

        $method->setAccessible(true);

        $data = $method->invoke($service, $report);

        $this->assertSame(7, $data['week']['number']);
        $this->assertSame('2026-10-26', $data['week']['start_date']);
        $this->assertSame('2026-10-30', $data['week']['end_date']);
        $this->assertSame(
            $term->term_name,
            $data['week']['term']
        );
        $this->assertSame(
            $academicYear->year_name,
            $data['week']['academic_year']
        );
    }

    public function test_pdf_decrypts_only_the_report_schools_logo(): void
    {
        $period = app(TeacherDutyRosterService::class)->createPeriod(
            (string) $this->school->id,
            '2026-10-12',
            '2026-10-16',
            null,
            (string) $this->reporter->id
        );

        $teacher = Teacher::query()
            ->withoutGlobalScopes()
            ->where('school_id', $this->school->id)
            ->where('user_id', $this->reporter->id)
            ->firstOrFail();

        app(TeacherDutyRosterService::class)->assignTeacher(
            (string) $this->school->id,
            (string) $period->id,
            (string) $teacher->id,
            (string) $this->reporter->id
        );

        $report = app(TeacherDutyWeeklyReportService::class)->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        $otherSchool = $this->createSchool();
        $otherUser = $this->createUser($otherSchool);

        $schoolALogo = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );

        $schoolBLogo = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII=',
            true
        );

        $this->assertIsString($schoolALogo);
        $this->assertIsString($schoolBLogo);
        $this->assertNotSame($schoolALogo, $schoolBLogo);

        $schoolAAsset = $this->storeBrandingLogo(
            $this->school,
            $this->reporter,
            $schoolALogo,
            now()->subMinute()
        );

        $schoolBAsset = $this->storeBrandingLogo(
            $otherSchool,
            $otherUser,
            $schoolBLogo,
            now()
        );

        $this->assertNotSame(
            $schoolAAsset['storage_id'],
            $schoolBAsset['storage_id']
        );

        $service = app(TeacherDutyWeeklyReportPdfService::class);

        $method = new \ReflectionMethod(
            TeacherDutyWeeklyReportPdfService::class,
            'schoolLogo'
        );

        $method->setAccessible(true);

        $decryptedPath = $method->invoke($service, $report);

        $this->assertIsString($decryptedPath);
        $this->assertFileExists($decryptedPath);

        try {
            $this->assertSame(
                $schoolALogo,
                file_get_contents($decryptedPath)
            );

            $this->assertNotSame(
                $schoolBLogo,
                file_get_contents($decryptedPath)
            );
        } finally {
            if (is_string($decryptedPath) && is_file($decryptedPath)) {
                @unlink($decryptedPath);
            }
        }
    }

    private function storeBrandingLogo(
        School $school,
        User $uploadedBy,
        string $bytes,
        \DateTimeInterface $approvedAt
    ): array {
        $sourcePath = storage_path(
            'app/private/teacher-duty-pdf-test-'.Str::uuid().'.png'
        );

        file_put_contents($sourcePath, $bytes);

        try {
            $stored = app(SecureFileStorage::class)->storePath(
                $sourcePath
            );
        } finally {
            if (is_file($sourcePath)) {
                @unlink($sourcePath);
            }
        }

        DB::table('administrator_branding_assets')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'asset_type' => 'logo',
            'original_filename' => 'school-logo.png',
            'mime_type' => 'image/png',
            'size' => $stored['size'],
            'source_hash' => $stored['source_hash'],
            'stored_hash' => $stored['stored_hash'],
            'storage_id' => $stored['storage_id'],
            'status' => 'approved',
            'version' => 1,
            'uploaded_by' => $uploadedBy->id,
            'approved_at' => $approvedAt,
            'archived_at' => null,
            'created_at' => $approvedAt,
            'updated_at' => $approvedAt,
        ]);

        return $stored;
    }

    public function test_pdf_removes_decrypted_logo_after_rendering(): void
    {
        $period = app(TeacherDutyRosterService::class)->createPeriod(
            (string) $this->school->id,
            '2026-10-19',
            '2026-10-23',
            null,
            (string) $this->reporter->id
        );

        $teacher = Teacher::query()
            ->withoutGlobalScopes()
            ->where('school_id', $this->school->id)
            ->where('user_id', $this->reporter->id)
            ->firstOrFail();

        app(TeacherDutyRosterService::class)->assignTeacher(
            (string) $this->school->id,
            (string) $period->id,
            (string) $teacher->id,
            (string) $this->reporter->id
        );

        $report = app(TeacherDutyWeeklyReportService::class)->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        $logo = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );

        $this->assertIsString($logo);

        $this->storeBrandingLogo(
            $this->school,
            $this->reporter,
            $logo,
            now()
        );

        $directory = storage_path('app/private/pdf-temp');

        $before = glob(
            $directory.DIRECTORY_SEPARATOR.'teacher-duty-logo-*'
        ) ?: [];

        $this->actingAsJwt($this->reporter);

        $response = $this->get(
            "/api/teacher-duty/weekly-reports/{$report->id}/pdf"
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith(
            '%PDF',
            $response->getContent()
        );

        $after = glob(
            $directory.DIRECTORY_SEPARATOR.'teacher-duty-logo-*'
        ) ?: [];

        $this->assertSame(
            $before,
            $after,
            'Teacher Duty PDF left a decrypted school logo on disk.'
        );
    }

    public function test_pdf_resolves_only_same_school_approved_logo(): void
    {
        $source = file_get_contents(
            app_path('Services/Pdf/TeacherDutyWeeklyReportPdfService.php')
        );

        $this->assertStringContainsString(
            "where('school_id', \$report->school_id)",
            $source
        );

        $this->assertStringContainsString(
            "where('asset_type', 'logo')",
            $source
        );

        $this->assertStringContainsString(
            "where('status', 'approved')",
            $source
        );

        $this->assertStringContainsString(
            "whereNull('archived_at')",
            $source
        );

        $this->assertStringContainsString(
            'decryptToPath',
            $source
        );

        $this->assertStringNotContainsString(
            "administrator_branding_assets')->where('asset_type', 'logo')->where('status', 'approved')",
            $source
        );
    }
}
