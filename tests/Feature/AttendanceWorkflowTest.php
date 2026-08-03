<?php

namespace Tests\Feature;

use App\Http\Resources\AttendanceRegisterResource;
use App\Models\AttendanceRegister;
use App\Services\Attendance\AttendanceStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AttendanceWorkflowTest extends TestCase
{
    public function test_status_classification_is_centralized(): void
    {
        $s = app(AttendanceStatusService::class);
        $this->assertSame('attended', $s->category('PRESENT'));
        $this->assertSame('late', $s->category('LATE'));
        $this->assertSame('absent', $s->category('ABSENT'));
        $this->assertSame('excused', $s->category('SICK'));
        $this->assertSame('excluded', $s->category('SUSPENDED'));
        $this->assertTrue($s->attended('LATE'));
        $this->assertFalse($s->denominator('SUSPENDED'));
    }

    public function test_register_resource_hides_ownership_and_audit_fields(): void
    {
        $m = new AttendanceRegister(['id' => 'r1', 'school_id' => 'secret-school', 'opened_by' => 'secret-user', 'deleted_by' => 'deleted-user', 'attendance_date' => '2026-07-14', 'register_type' => 'daily', 'status' => 'draft']);
        $d = (new AttendanceRegisterResource($m))->toArray(Request::create('/'));
        $this->assertArrayNotHasKey('school_id', $d);
        $this->assertArrayNotHasKey('opened_by', $d);
        $this->assertArrayNotHasKey('deleted_by', $d);
    }

    public function test_production_attendance_routes_are_registered(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->methods()[0].' '.$r->uri());
        foreach (['POST api/teacher/attendance/registers', 'PUT api/teacher/attendance/registers/{register}/draft', 'POST api/teacher/attendance/registers/{register}/finalize', 'GET api/learner/attendance/summary', 'GET api/parent/learners/{learner}/attendance/summary', 'GET api/attendance/analytics'] as $route) {
            $this->assertContains($route, $uris);
        }
    }

    public function test_no_discipline_or_official_result_routes_are_added(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'attendance'))->pluck('uri');
        foreach ($uris as $uri) {
            $this->assertStringNotContainsString('discipline', $uri);
            $this->assertStringNotContainsString('exam-results', $uri);
        }
    }
}
