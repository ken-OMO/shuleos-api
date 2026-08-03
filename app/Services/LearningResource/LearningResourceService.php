<?php

namespace App\Services\LearningResource;

use App\Models\LearningResource;
use App\Models\LearningResourceVersion;
use App\Models\User;
use App\Services\LeadershipPortal\LeadershipPortalAccessService;
use App\Services\LearnerPortal\LearnerPortalAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LearningResourceService
{
    public function validateScope(string $school, string $user, array &$data): void
    {
        foreach (['learning_area_id' => 'learning_areas', 'grade_id' => 'grades', 'stream_id' => 'streams', 'academic_year_id' => 'academic_years', 'term_id' => 'terms', 'scheme_id' => 'schemes_of_work', 'scheme_lesson_id' => 'scheme_lessons', 'category_id' => 'learning_resource_categories'] as $field => $table) {
            if (empty($data[$field])) {
                continue;
            }
            $query = DB::table($table)->where('id', $data[$field]);
            if (Schema::hasColumn($table, 'school_id')) {
                $query->where('school_id', $school);
            }
            if (Schema::hasColumn($table, 'is_deleted')) {
                $query->where('is_deleted', false);
            }
            if (! $query->exists()) {
                throw ValidationException::withMessages([$field => 'Referenced entity does not belong to this school.']);
            }
        }
        if (! empty($data['stream_id']) && ! DB::table('streams')->where('id', $data['stream_id'])->where('grade_id', $data['grade_id'])->where('school_id', $school)->exists()) {
            throw ValidationException::withMessages(['stream_id' => 'Stream does not belong to the selected grade.']);
        }
        if (! empty($data['scheme_lesson_id'])) {
            $lesson = DB::table('scheme_lessons')->where('id', $data['scheme_lesson_id'])->where('is_deleted', false)->first();
            $data['strand'] = $lesson->strand;
            $data['sub_strand'] = $lesson->sub_strand;
        }
        $teacher = DB::table('teachers')->where('user_id', $user)->where('school_id', $school)->where('active', true)->where('is_deleted', false)->first();
        if ($teacher && ! DB::table('teacher_assignments')->where('school_id', $school)->where('teacher_id', $teacher->id)->where('learning_area_id', $data['learning_area_id'])->where('grade_id', $data['grade_id'])->when($data['stream_id'] ?? null, fn ($q, $v) => $q->where('stream_id', $v))->where('active', true)->where('is_deleted', false)->exists()) {
            throw new AuthorizationException('Resource is outside active teacher assignments.');
        }
    }

    public function teacherQuery(User $user, array $filters = []): Builder
    {
        $query = LearningResource::current()->where('school_id', $user->school_id)->where('uploaded_by', $user->id);
        foreach (['learning_area_id', 'grade_id', 'stream_id', 'resource_type', 'category_id', 'publication_status', 'strand', 'sub_strand', 'academic_year_id', 'term_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['keyword'])) {
            $term = mb_strtolower($filters['keyword']);
            $query->where(fn ($q) => $q->whereRaw('LOWER(title) LIKE ?', ["%{$term}%"])->orWhereRaw('LOWER(description) LIKE ?', ["%{$term}%"]));
        }

        return $query;
    }

    public function external(string $school, string $user, array $data): LearningResource
    {
        $this->validateScope($school, $user, $data);
        $url = $this->safeUrl($data['external_url'], $data['resource_type'] ?? null);

        return DB::transaction(function () use ($school, $user, $data, $url) {
            $resource = LearningResource::create($data + ['id' => (string) Str::uuid(), 'school_id' => $school, 'source_type' => 'external_link', 'external_url' => $url, 'publication_status' => 'draft', 'uploaded_by' => $user, 'current_version_number' => 1]);
            LearningResourceVersion::create(['id' => (string) Str::uuid(), 'school_id' => $school, 'resource_id' => $resource->id, 'version_number' => 1, 'external_url' => $url, 'encrypted' => false, 'change_notes' => $data['change_notes'] ?? null, 'created_by' => $user, 'created_at' => now()]);

            return $resource->load($this->relations());
        });
    }

    public function safeUrl(string $url, ?string $type = null): string
    {
        if (preg_match('/[<>]/', $url)) {
            throw ValidationException::withMessages(['external_url' => 'HTML and embed markup are prohibited.']);
        }
        $parts = parse_url(trim($url));
        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages(['external_url' => 'A credential-free HTTPS URL is required.']);
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        if (filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw ValidationException::withMessages(['external_url' => 'IP and local hosts are prohibited.']);
        }
        if ($type === 'video') {
            $allowed = collect(config('learning_resources.video_providers', []))->contains(fn ($rules, $domain) => $host === $domain || (($rules['allow_subdomains'] ?? false) && str_ends_with($host, '.'.$domain)));
            if (! $allowed) {
                throw ValidationException::withMessages(['external_url' => 'Video provider is not approved.']);
            }
        }

        return 'https://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    public function updateOwn(User $user, string $id, array $data): LearningResource
    {
        return DB::transaction(function () use ($user, $id, $data) {
            $resource = $this->ownedEditable($user, $id, true);
            $scope = array_merge($resource->only(['category_id', 'learning_area_id', 'grade_id', 'stream_id', 'academic_year_id', 'term_id', 'scheme_id', 'scheme_lesson_id']), $data);
            $this->validateScope($user->school_id, $user->id, $scope);
            $resource->update(collect($data)->except(['school_id', 'uploaded_by', 'source_type', 'external_url', 'publication_status', 'approved_by', 'published_by'])->all());

            return $resource->fresh($this->relations());
        });
    }

    public function addLinkVersion(User $user, string $id, string $url, ?string $notes): LearningResourceVersion
    {
        return DB::transaction(function () use ($user, $id, $url, $notes) {
            $resource = $this->ownedEditable($user, $id, true);
            $url = $this->safeUrl($url, $resource->resource_type);
            $resource->lockForUpdate();
            $number = $resource->current_version_number + 1;
            $version = LearningResourceVersion::create(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'resource_id' => $resource->id, 'version_number' => $number, 'external_url' => $url, 'encrypted' => false, 'change_notes' => $notes, 'created_by' => $user->id, 'created_at' => now()]);
            $resource->update(['source_type' => 'external_link', 'external_url' => $url, 'current_version_number' => $number]);

            return $version;
        });
    }

    public function restore(User $user, string $id, string $versionId, ?string $notes): LearningResourceVersion
    {
        return DB::transaction(function () use ($user, $id, $versionId, $notes) {
            $resource = $this->ownedEditableOrManager($user, $id);
            $resource = LearningResource::whereKey($resource->id)->where('school_id', $user->school_id)->lockForUpdate()->firstOrFail();
            $old = $resource->versions()->whereKey($versionId)->firstOrFail();
            $copy = $old->replicate(['id', 'version_number', 'created_by', 'created_at']);
            $copy->forceFill(['id' => (string) Str::uuid(), 'version_number' => $resource->current_version_number + 1, 'change_notes' => $notes ?: 'Restored from version '.$old->version_number, 'created_by' => $user->id, 'created_at' => now()])->save();
            $resource->update(['current_version_number' => $copy->version_number, 'source_type' => $copy->storage_id ? 'uploaded_file' : 'external_link', 'external_url' => $copy->external_url]);

            return $copy;
        });
    }

    public function transition(string $school, string $id, string $to, User $actor, ?string $comments = null): LearningResource
    {
        return DB::transaction(function () use ($school, $id, $to, $actor, $comments) {
            $allowed = ['draft' => ['pending_review', 'archived'], 'rejected' => ['pending_review', 'archived'], 'pending_review' => ['approved', 'rejected'], 'approved' => ['published', 'archived'], 'published' => ['archived']];
            $resource = LearningResource::current()->whereKey($id)->where('school_id', $school)->lockForUpdate()->firstOrFail();
            if ($to === 'pending_review' || ($to === 'archived' && in_array($resource->publication_status, ['draft', 'rejected'], true))) {
                abort_unless($resource->uploaded_by === $actor->id, 403);
            } else {
                $this->authorizeReviewScope($actor, $resource);
            }
            if (! in_array($to, $allowed[$resource->publication_status] ?? [], true)) {
                throw ValidationException::withMessages(['publication_status' => 'Invalid resource lifecycle transition.']);
            }
            $values = ['publication_status' => $to, 'approval_comments' => $comments];
            if ($to === 'approved') {
                $values += ['approved_by' => $actor->id, 'approved_at' => now()];
            } elseif ($to === 'published') {
                $values += ['published_by' => $actor->id, 'published_at' => now()];
            } elseif ($to === 'archived') {
                $values += ['archived_by' => $actor->id, 'archived_at' => now()];
            }
            $resource->update($values);

            return $resource->fresh($this->relations());
        });
    }

    public function authorizeReviewScope(User $user, LearningResource $resource): void
    {
        $scope = app(LeadershipPortalAccessService::class)->scope($user);
        if (! $scope['whole_school'] && ! in_array($resource->learning_area_id, $scope['learning_area_ids'], true)) {
            throw new AuthorizationException('Resource is outside the active HOD learning-area scope.');
        }
    }

    public function learnerResources(User $user, LearnerPortalAccessService $access)
    {
        $learner = $access->learner($user);

        return $this->publishedForLearner($learner->school_id, $learner->grade_id, $learner->stream_id, ['assigned_class', 'grade', 'school'])->with($this->relations())->paginate(20);
    }

    public function publishedForLearner(string $school, string $grade, ?string $stream, array $visibility): Builder
    {
        return LearningResource::current()->where('school_id', $school)->where('publication_status', 'published')->where('grade_id', $grade)->where(fn ($q) => $q->whereNull('stream_id')->orWhere('stream_id', $stream))->whereIn('visibility', $visibility);
    }

    public function logAccess(User $user, LearningResource $resource, LearningResourceVersion $version, string $action, ?string $learnerId = null): void
    {
        DB::table('learning_resource_access_logs')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'resource_id' => $resource->id, 'version_id' => $version->id, 'user_id' => $user->id, 'learner_id' => $learnerId, 'action' => $action, 'occurred_at' => now()]);
    }

    public function bookmark(User $user, LearningResource $resource, bool $add = true): void
    {
        abort_unless($resource->school_id === $user->school_id && $resource->publication_status === 'published', 404);
        if ($add) {
            DB::table('learning_resource_bookmarks')->insertOrIgnore(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'resource_id' => $resource->id, 'user_id' => $user->id, 'learner_id' => $user->learner?->id, 'created_at' => now()]);
        } else {
            DB::table('learning_resource_bookmarks')->where('user_id', $user->id)->where('resource_id', $resource->id)->delete();
        }
    }

    public function rate(User $user, LearningResource $resource, int $rating, ?string $review): void
    {
        abort_unless($resource->school_id === $user->school_id && $resource->publication_status === 'published', 404);
        $existing = DB::table('learning_resource_ratings')->where('user_id', $user->id)->where('resource_id', $resource->id)->first();
        $values = ['school_id' => $user->school_id, 'learner_id' => $user->learner?->id, 'rating' => $rating, 'review' => $review ? strip_tags($review) : null, 'updated_at' => now()];
        if ($existing) {
            DB::table('learning_resource_ratings')->where('id', $existing->id)->update($values);
        } else {
            DB::table('learning_resource_ratings')->insert($values + ['id' => (string) Str::uuid(), 'resource_id' => $resource->id, 'user_id' => $user->id, 'created_at' => now()]);
        }
    }

    public function ownedEditable(User $user, string $id, bool $lock = false): LearningResource
    {
        $query = LearningResource::current()->whereKey($id)->where('school_id', $user->school_id)->where('uploaded_by', $user->id)->whereIn('publication_status', ['draft', 'rejected']);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    public function ownedEditableOrManager(User $user, string $id): LearningResource
    {
        $resource = LearningResource::current()->whereKey($id)->where('school_id', $user->school_id)->firstOrFail();
        if ($resource->uploaded_by !== $user->id) {
            $this->authorizeReviewScope($user, $resource);
        } elseif (! in_array($resource->publication_status, ['draft', 'rejected'], true)) {
            throw new AuthorizationException('Only draft or rejected resources can be versioned.');
        }

        return $resource;
    }

    public function relations(): array
    {
        return ['currentVersion', 'category', 'learningArea', 'grade', 'stream', 'uploader'];
    }
}
