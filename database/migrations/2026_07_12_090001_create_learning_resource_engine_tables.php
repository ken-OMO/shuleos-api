<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_resource_categories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->string('name');
            $t->string('code')->nullable();
            $t->text('description')->nullable();
            $t->boolean('active')->default(true);
            $t->foreignUuid('created_by')->constrained('users');
            $t->timestamps();
            $t->boolean('is_deleted')->default(false);
            $t->timestamp('deleted_at')->nullable();
            $t->foreignUuid('deleted_by')->nullable()->constrained('users');
            $t->index(['school_id', 'active', 'is_deleted']);
        });
        Schema::create('learning_resources', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('category_id')->nullable()->constrained('learning_resource_categories');
            $t->foreignUuid('learning_area_id')->constrained('learning_areas');
            $t->foreignUuid('grade_id')->constrained('grades');
            $t->foreignUuid('stream_id')->nullable()->constrained('streams');
            $t->foreignUuid('academic_year_id')->nullable()->constrained('academic_years');
            $t->foreignUuid('term_id')->nullable()->constrained('terms');
            $t->foreignUuid('scheme_id')->nullable()->constrained('schemes_of_work');
            $t->foreignUuid('scheme_lesson_id')->nullable()->constrained('scheme_lessons');
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('strand')->nullable();
            $t->string('sub_strand')->nullable();
            $t->string('resource_type');
            $t->string('source_type');
            $t->text('external_url')->nullable();
            $t->string('visibility')->default('assigned_class');
            $t->string('publication_status')->default('draft');
            $t->text('approval_comments')->nullable();
            foreach (['approved_by', 'published_by', 'archived_by'] as $f) {
                $t->foreignUuid($f)->nullable()->constrained('users');
            }foreach (['approved_at', 'published_at', 'archived_at'] as $f) {
                $t->timestamp($f)->nullable();
            }$t->unsignedInteger('current_version_number')->default(1);
            $t->foreignUuid('uploaded_by')->constrained('users');
            $t->timestamps();
            $t->boolean('is_deleted')->default(false);
            $t->timestamp('deleted_at')->nullable();
            $t->foreignUuid('deleted_by')->nullable()->constrained('users');
            $t->index(['school_id', 'publication_status', 'grade_id', 'stream_id']);
        });
        Schema::create('learning_resource_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('resource_id')->constrained('learning_resources');
            $t->unsignedInteger('version_number');
            $t->string('storage_id')->nullable();
            $t->string('original_filename')->nullable();
            $t->string('safe_download_filename')->nullable();
            $t->string('mime_type')->nullable();
            $t->string('extension')->nullable();
            $t->unsignedBigInteger('source_size')->nullable();
            $t->unsignedBigInteger('stored_size')->nullable();
            $t->string('source_hash')->nullable();
            $t->string('stored_hash')->nullable();
            $t->boolean('encrypted')->default(true);
            $t->text('external_url')->nullable();
            $t->text('change_notes')->nullable();
            $t->foreignUuid('created_by')->constrained('users');
            $t->timestamp('created_at');
            $t->unique(['resource_id', 'version_number']);
        });
        Schema::create('learning_resource_access_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('resource_id')->constrained('learning_resources');
            $t->foreignUuid('version_id')->nullable()->constrained('learning_resource_versions');
            $t->foreignUuid('user_id')->constrained('users');
            $t->foreignUuid('learner_id')->nullable()->constrained('learners');
            $t->string('action');
            $t->timestamp('occurred_at');
            $t->jsonb('metadata')->nullable();
            $t->index(['school_id', 'resource_id', 'action']);
        });
        Schema::create('learning_resource_bookmarks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('resource_id')->constrained('learning_resources');
            $t->foreignUuid('user_id')->constrained('users');
            $t->foreignUuid('learner_id')->nullable()->constrained('learners');
            $t->timestamp('created_at');
            $t->unique(['user_id', 'resource_id']);
        });
        Schema::create('learning_resource_ratings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('resource_id')->constrained('learning_resources');
            $t->foreignUuid('user_id')->constrained('users');
            $t->foreignUuid('learner_id')->nullable()->constrained('learners');
            $t->unsignedTinyInteger('rating');
            $t->text('review')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'resource_id']);
        });
    }

    public function down(): void
    {
        foreach (['learning_resource_ratings', 'learning_resource_bookmarks', 'learning_resource_access_logs', 'learning_resource_versions', 'learning_resources', 'learning_resource_categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
