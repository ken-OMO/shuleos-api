<?php

namespace Tests\Feature;

use App\Services\Assessment\AssessmentTypeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AssessmentTypeTest extends TestCase
{
    private string $school;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('exams');
        Schema::dropIfExists('assessment_types');
        Schema::create('assessment_types', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->string('assessment_type_name');
            $t->boolean('active');
            $t->boolean('is_deleted');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->uuid('deleted_by')->nullable();
        });
        Schema::create('exams', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('assessment_type_id');
        });
        $this->school = (string) Str::uuid();
    }

    public function test_it_creates_a_tenant_assessment_type(): void
    {
        $t = app(AssessmentTypeService::class)->create(['assessment_type_name' => 'Formative'], $this->school);
        $this->assertSame($this->school, $t->school_id);
        $this->assertTrue($t->active);
    }

    public function test_it_rejects_case_insensitive_duplicates(): void
    {
        $s = app(AssessmentTypeService::class);
        $s->create(['assessment_type_name' => 'Summative'], $this->school);
        $this->expectException(ValidationException::class);
        $s->create(['assessment_type_name' => 'summative'], $this->school);
    }

    public function test_same_name_is_allowed_in_another_school(): void
    {
        $s = app(AssessmentTypeService::class);
        $s->create(['assessment_type_name' => 'CAT'], $this->school);
        $other = $s->create(['assessment_type_name' => 'CAT'], (string) Str::uuid());
        $this->assertNotSame($this->school, $other->school_id);
    }
}
