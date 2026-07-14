<?php

namespace Tests\Feature;

use App\Http\Resources\TimetableResource;
use App\Models\Timetable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SmartTimetablePhaseOneTest extends TestCase
{
    public function test_phase_one_schema_uses_assignment_authority_and_explicit_school_days(): void
    {
        $source = file_get_contents(database_path('migrations/2026_07_14_020001_harden_smart_timetable_phase_one.php'));

        foreach (['timetable_days', 'teacher_assignment_id', 'timetable_day_id', 'entry_status', 'validation_summary', 'validated_at'] as $column) {
            $this->assertStringContainsString($column, $source);
        }
    }

    public function test_hardened_management_and_portal_routes_are_registered(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->methods()[0].' '.$route->uri());
        foreach (['POST api/timetables/{timetable}/entries', 'POST api/timetables/{timetable}/validate', 'POST api/timetables/{timetable}/approve', 'POST api/timetables/{timetable}/publish', 'GET api/teacher/timetable/today', 'GET api/learner/timetable/week', 'GET api/parent/learners/{learner}/timetable', 'GET api/timetable/current-period'] as $expected) {
            $this->assertContains($expected, $routes);
        }
    }

    public function test_legacy_entry_and_generation_writes_are_disabled(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->methods()[0].' '.$route->uri());
        $this->assertNotContains('POST api/timetable-entries', $routes);
        $this->assertNotContains('PUT api/timetable-entries/{id}', $routes);
        $this->assertNotContains('POST api/timetable-generation-runs', $routes);
        $this->assertNotContains('POST api/timetable-publications', $routes);
    }

    public function test_timetable_resource_hides_tenant_and_ownership_fields(): void
    {
        $data = (new TimetableResource(new Timetable(['id' => 'x', 'school_id' => 'secret', 'created_by' => 'secret-user', 'deleted_by' => 'secret-deleter'])))->toArray(Request::create('/'));
        $this->assertArrayNotHasKey('school_id', $data);
        $this->assertArrayNotHasKey('created_by', $data);
        $this->assertArrayNotHasKey('deleted_by', $data);
    }

    public function test_portal_queries_require_published_active_timetables(): void
    {
        foreach (['TeacherPortal/TeacherPortalService.php', 'LearnerPortal/LearnerPortalService.php', 'ParentPortal/ParentPortalService.php'] as $service) {
            $source = file_get_contents(app_path('Services/'.$service));
            $this->assertStringContainsString("where('t.status', 'published')", str_replace("where('tt.status', 'published')", "where('t.status', 'published')", $source));
            $this->assertStringContainsString("where('t.active', true)", str_replace("where('tt.active', true)", "where('t.active', true)", $source));
        }
    }

    public function test_no_solver_or_ai_generator_was_added(): void
    {
        $files = collect(glob(app_path('Services/Timetable/*.php')))->map(fn ($path) => basename($path));
        $this->assertFalse($files->contains(fn ($file) => str_contains(strtolower($file), 'generator') || str_contains(strtolower($file), 'solver')));
    }
}
