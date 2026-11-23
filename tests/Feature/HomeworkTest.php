<?php

namespace Tests\Feature;

use App\Core\Security\File\Factories\FilePolicyFactory;
use App\Http\Middleware\RequireEnterpriseHomework;
use App\Http\Resources\HomeworkSubmissionFileResource;
use App\Http\Resources\HomeworkSubmissionResource;
use App\Models\HomeworkSubmission;
use App\Models\HomeworkSubmissionFile;
use App\Services\Homework\EnterpriseHomeworkAccess;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Database\MainSubscriptionBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\TestCase;

class HomeworkTest extends TestCase
{
    use DatabaseTransactions;

    public function test_homework_entitlement_follows_the_current_school_subscription(): void
    {
        $school = SchoolBuilder::create(['lifecycle_state' => 'active', 'timezone' => 'UTC']);
        $access = app(EnterpriseHomeworkAccess::class);
        $this->assertFalse($access->allows($school->id));

        $subscription = MainSubscriptionBuilder::activeFor($school->id);
        $package = DB::table('subscription_packages')->where('id', $subscription->package_id);
        foreach (['Basic', 'Premium'] as $name) {
            $package->update(['package_name' => $name]);
            $this->assertFalse($access->allows($school->id));
        }

        $package->update(['package_name' => 'Enterprise']);
        $this->assertTrue($access->allows($school->id));
        $row = DB::table('school_subscriptions')->where('id', $subscription->id);
        $row->update(['expiry_date' => now('UTC')->subDay()->toDateString()]);
        $this->assertFalse($access->allows($school->id));
        $row->update(['status' => 'TRIAL', 'is_trial' => true, 'expiry_date' => now('UTC')->addDay()->toDateString()]);
        DB::table('schools')->where('id', $school->id)->update(['lifecycle_state' => 'trial']);
        $this->assertTrue($access->allows($school->id));
    }

    public function test_homework_file_policy_is_secure_and_excludes_dangerous_types(): void
    {
        $policy = FilePolicyFactory::homeworkSubmission();
        foreach (['exe', 'php', 'js', 'html', 'svg', 'zip', 'docm', 'xlsm', 'pptm'] as $extension) {
            $this->assertNotContains($extension, $policy->allowedExtensions);
        }
        $this->assertTrue($policy->requireVirusScan);
        $this->assertTrue($policy->requireMagicNumberValidation);
        $this->assertTrue($policy->requireQuarantine);
        $this->assertTrue($policy->encryptAfterUpload);
        $this->assertFalse($policy->allowMacros);
        $this->assertFalse($policy->allowPasswordProtectedFiles);
    }

    public function test_submission_file_resources_never_expose_secure_storage_metadata(): void
    {
        $file = new HomeworkSubmissionFile(['id' => 'f1', 'storage_id' => 'secret-storage', 'source_hash' => 'secret-source', 'stored_hash' => 'secret-stored', 'original_filename' => 'answer.pdf', 'encrypted' => true]);
        $data = (new HomeworkSubmissionFileResource($file))->toArray(Request::create('/'));
        $this->assertArrayNotHasKey('storage_id', $data);
        $this->assertArrayNotHasKey('source_hash', $data);
        $this->assertArrayNotHasKey('stored_hash', $data);
        $this->assertStringNotContainsString('secret', json_encode($file->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_submission_resource_does_not_expose_learner_or_tenant_ownership(): void
    {
        $submission = new HomeworkSubmission(['id' => 's1', 'school_id' => 'school-secret', 'learner_id' => 'learner-secret', 'assignment_learner_id' => 'record-secret', 'attempt_number' => 1, 'submission_status' => 'draft']);
        $data = (new HomeworkSubmissionResource($submission))->toArray(Request::create('/'));
        $this->assertArrayNotHasKey('school_id', $data);
        $this->assertArrayNotHasKey('learner_id', $data);
        $this->assertArrayNotHasKey('assignment_learner_id', $data);
    }

    public function test_homework_routes_do_not_expose_exam_result_writes(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'homework'));
        $this->assertNotEmpty($routes);
        foreach ($routes as $route) {
            $this->assertStringNotContainsString('exam-result', $route->uri());
            $this->assertStringNotContainsString('learning-area-result', $route->uri());
        }
    }

    public function test_dedicated_homework_routes_require_enterprise(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => preg_match(
            '~^api/(?:homework(?:/|$)|(?:teacher|learner)/homework(?:/|$))~', $route->uri()
        ));
        $this->assertNotEmpty($routes);
        foreach ($routes as $route) {
            $this->assertContains(RequireEnterpriseHomework::class, $route->gatherMiddleware(), $route->uri());
        }
    }

    public function test_phase_two_routes_and_commands_are_registered(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->methods()[0].' '.$route->uri());
        foreach (['GET api/teacher/homework/{assignment}/rubric', 'GET api/teacher/homework/{assignment}/submissions/{submission}/files/{file}/download', 'DELETE api/learner/homework/{assignment}/submission/files/{file}', 'POST api/homework/submissions/{submission}/moderate'] as $route) {
            $this->assertContains($route, $uris);
        }
        $commands = Artisan::all();
        $this->assertArrayHasKey('homework:publish-scheduled', $commands);
        $this->assertArrayHasKey('homework:send-reminders', $commands);
    }
}
