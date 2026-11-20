<?php

namespace Tests\Feature;

use App\Services\SchoolReadinessSummaryService;
use App\Services\SchoolSetupReadinessService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

class SchoolReadinessSummaryTest extends TestCase
{
    use DatabaseTransactions;

    private function fixture(): array
    {
        $school = SchoolBuilder::create([
            'short_name' => 'Ready', 'registration_number' => 'REG-'.Str::random(12),
            'school_type' => 'Junior School', 'operating_mode' => 'day',
            'county' => 'Nairobi', 'phone' => '07'.random_int(10000000, 99999999),
            'email' => 'school-'.Str::lower(Str::random(12)).'@example.test',
            'timezone' => 'Africa/Nairobi', 'locale' => 'en',
        ]);
        $year = AcademicYearBuilder::create($school);
        $term = TermBuilder::create($school, $year);
        $grade = GradeBuilder::create($school);
        $stream = StreamBuilder::create($school, $grade);
        $area = LearningAreaBuilder::create();
        $allocation = LearningAreaAllocationBuilder::create($school, $grade, $area);
        $user = UserBuilder::create($school, RoleBuilder::create());
        $teacher = TeacherBuilder::create($school, $user);

        return compact('school', 'year', 'term', 'grade', 'stream', 'area', 'allocation', 'teacher');
    }

    private function summary(array $f): array
    {
        return app(SchoolReadinessSummaryService::class)->summary($f['school']->id);
    }

    private function assign(array $f, array $attributes = []): string
    {
        $id = (string) Str::uuid();
        DB::table('teacher_assignments')->insert(array_merge([
            'id' => $id, 'school_id' => $f['school']->id, 'teacher_id' => $f['teacher']->id,
            'academic_year_id' => $f['year']->id, 'term_id' => $f['term']->id,
            'grade_id' => $f['grade']->id, 'stream_id' => $f['stream']->id,
            'learning_area_id' => $f['area']->id, 'lessons_per_week' => 5,
            'is_class_teacher' => true, 'active' => true, 'is_deleted' => false,
        ], $attributes));

        return $id;
    }

    public function test_missing_teachers_do_not_change_initial_setup_or_admission_gate(): void
    {
        $f = $this->fixture();
        DB::table('teachers')->where('id', $f['teacher']->id)->update(['active' => false]);
        $summary = $this->summary($f);
        $this->assertTrue($summary['initial_setup']['complete']);
        $this->assertTrue($summary['academic_configuration']['complete']);
        $this->assertFalse($summary['teaching_coverage']['complete']);
        $this->assertContains('active_teachers', array_column($summary['teaching_coverage']['missing'], 'code'));
        $this->assertTrue(app(SchoolSetupReadinessService::class)->isReady($f['school']->id));
    }

    public function test_complete_connected_configuration_has_teaching_coverage(): void
    {
        $f = $this->fixture();
        $this->assign($f);
        $summary = $this->summary($f);
        $this->assertTrue($summary['academic_configuration']['complete']);
        $this->assertTrue($summary['teaching_coverage']['complete']);
        $this->assertSame([], $summary['teaching_coverage']['missing']);
    }

    public function test_inactive_parent_records_and_missing_grade_allocations_are_reported(): void
    {
        $f = $this->fixture();
        DB::table('academic_years')->where('id', $f['year']->id)->update(['active' => false]);
        DB::table('grades')->where('id', $f['grade']->id)->update(['active' => false]);
        GradeBuilder::create($f['school'], null, ['grade_name' => 'Unconfigured grade']);
        $codes = array_column($this->summary($f)['academic_configuration']['missing'], 'code');
        foreach (['terms_without_active_year', 'streams_without_active_grade', 'grades_without_streams', 'grades_without_learning_areas'] as $code) {
            $this->assertContains($code, $codes);
        }
    }

    public function test_foreign_school_assignments_cannot_fill_coverage(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        $this->assign($other);
        $summary = $this->summary($f);
        $this->assertFalse($summary['teaching_coverage']['complete']);
        $items = collect($summary['teaching_coverage']['missing'])->firstWhere('code', 'unassigned_learning_areas');
        $this->assertSame(1, $items['count']);
        $this->assertSame($f['stream']->id, $items['items'][0]['stream_id']);
        $this->assertStringNotContainsString($other['school']->id, json_encode($summary));
        $this->assertStringNotContainsString($other['teacher']->id, json_encode($summary));
    }

    public function test_deleted_assignment_and_inactive_teacher_do_not_count(): void
    {
        $f = $this->fixture();
        $id = $this->assign($f);
        DB::table('teacher_assignments')->where('id', $id)->update(['is_deleted' => true]);
        $this->assertFalse($this->summary($f)['teaching_coverage']['complete']);
        DB::table('teacher_assignments')->where('id', $id)->update(['is_deleted' => false]);
        DB::table('teachers')->where('id', $f['teacher']->id)->update(['active' => false]);
        $this->assertFalse($this->summary($f)['teaching_coverage']['complete']);
    }

    public function test_each_active_term_requires_its_own_assignment(): void
    {
        $f = $this->fixture();
        $this->assign($f);
        $next = TermBuilder::create($f['school'], $f['year'], ['term_name' => 'Term 3']);
        $missing = collect($this->summary($f)['teaching_coverage']['missing'])->firstWhere('code', 'unassigned_learning_areas');
        $this->assertSame(1, $missing['count']);
        $this->assertSame($next->id, $missing['items'][0]['term_id']);
    }

    public function test_grade_wide_assignment_covers_subjects_but_not_class_teacher_for_each_stream(): void
    {
        $f = $this->fixture();
        $this->assign($f, ['stream_id' => null, 'is_class_teacher' => false]);
        $codes = array_column($this->summary($f)['teaching_coverage']['missing'], 'code');
        $this->assertNotContains('unassigned_learning_areas', $codes);
        $this->assertContains('streams_without_class_teachers', $codes);
    }

    public function test_term_outside_year_and_inactive_learning_area_are_reported(): void
    {
        $f = $this->fixture();
        DB::table('terms')->where('id', $f['term']->id)->update(['end_date' => '2027-01-01']);
        DB::table('learning_areas')->where('id', $f['area']->id)->update(['active' => false]);
        $codes = array_column($this->summary($f)['academic_configuration']['missing'], 'code');
        $this->assertContains('term_dates', $codes);
        $this->assertContains('grades_without_learning_areas', $codes);
        $this->assertContains('inactive_learning_area_allocations', $codes);
    }

    public function test_missing_details_are_bounded_without_hiding_total_count(): void
    {
        $f = $this->fixture();
        for ($i = 0; $i < 51; $i++) {
            GradeBuilder::create($f['school']);
        }
        $missing = collect($this->summary($f)['academic_configuration']['missing'])->firstWhere('code', 'grades_without_streams');
        $this->assertSame(51, $missing['count']);
        $this->assertCount(50, $missing['items']);
        $this->assertTrue($missing['truncated']);
    }
}
