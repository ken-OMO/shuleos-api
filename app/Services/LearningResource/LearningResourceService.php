<?php

namespace App\Services\LearningResource;

use App\Models\LearningResource;
use App\Models\LearningResourceVersion;
use App\Models\User;
use App\Services\LearnerPortal\LearnerPortalAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LearningResourceService
{
    public function validateScope(string $s, string $u, array &$d): void
    {
        foreach (['learning_area_id' => 'learning_areas', 'grade_id' => 'grades', 'stream_id' => 'streams', 'academic_year_id' => 'academic_years', 'term_id' => 'terms', 'scheme_id' => 'schemes_of_work', 'scheme_lesson_id' => 'scheme_lessons', 'category_id' => 'learning_resource_categories'] as $f => $table) {
            if (! empty($d[$f])) {
                $q = DB::table($table)->where('id', $d[$f]);
                if (Schema::hasColumn($table, 'school_id')) {
                    $q->where('school_id', $s);
                }if (! $q->exists()) {
                    throw ValidationException::withMessages([$f => 'Referenced entity does not belong to this school.']);
                }
            }
        }if (! empty($d['stream_id']) && ! DB::table('streams')->where('id', $d['stream_id'])->where('grade_id', $d['grade_id'])->where('school_id', $s)->exists()) {
            throw ValidationException::withMessages(['stream_id' => 'Stream does not belong to the selected grade.']);
        }if (! empty($d['scheme_lesson_id'])) {
            $lesson = DB::table('scheme_lessons')->where('id', $d['scheme_lesson_id'])->where('is_deleted', false)->first();
            if (! $lesson) {
                throw ValidationException::withMessages(['scheme_lesson_id' => 'Scheme lesson is unavailable.']);
            }$d['strand'] = $lesson->strand;
            $d['sub_strand'] = $lesson->sub_strand;
        }$teacher = DB::table('teachers')->where('user_id', $u)->where('school_id', $s)->where('active', true)->where('is_deleted', false)->first();
        if ($teacher && ! DB::table('teacher_assignments')->where('school_id', $s)->where('teacher_id', $teacher->id)->where('learning_area_id', $d['learning_area_id'])->where('grade_id', $d['grade_id'])->when($d['stream_id'] ?? null, fn ($q, $v) => $q->where('stream_id', $v))->where('active', true)->where('is_deleted', false)->exists()) {
            throw new AuthorizationException('Resource is outside active teacher assignments.');
        }
    }

    public function external(string $s, string $u, array $d): LearningResource
    {
        $this->validateScope($s, $u, $d);
        $url = $this->safeUrl($d['external_url']);

        return DB::transaction(function () use ($s, $u, $d, $url) {
            $r = LearningResource::create($d + ['id' => (string) Str::uuid(), 'school_id' => $s, 'source_type' => 'external_link', 'external_url' => $url, 'publication_status' => 'draft', 'uploaded_by' => $u, 'current_version_number' => 1]);
            LearningResourceVersion::create(['id' => (string) Str::uuid(), 'school_id' => $s, 'resource_id' => $r->id, 'version_number' => 1, 'external_url' => $url, 'encrypted' => false, 'created_by' => $u, 'created_at' => now()]);

            return $r->load('currentVersion');
        });
    }

    public function safeUrl(string $url): string
    {
        if (str_contains($url, '<') || str_contains($url, '>')) {
            throw ValidationException::withMessages(['external_url' => 'HTML and embed markup are prohibited.']);
        }$parts = parse_url(trim($url));
        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            throw ValidationException::withMessages(['external_url' => 'Only valid HTTPS links are allowed.']);
        }$host = strtolower($parts['host']);
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false && filter_var($host, FILTER_VALIDATE_IP)) {
            throw ValidationException::withMessages(['external_url' => 'Private or local hosts are prohibited.']);
        }

        return 'https://'.$host.($parts['port'] ?? null ? ':'.$parts['port'] : '').($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    public function transition(string $s, string $id, string $to, string $u, ?string $comments = null): LearningResource
    {
        $allowed = ['draft' => ['pending_review', 'archived'], 'pending_review' => ['approved', 'rejected'], 'approved' => ['published', 'archived'], 'published' => ['archived']];
        $r = LearningResource::current()->whereKey($id)->where('school_id', $s)->lockForUpdate()->firstOrFail();
        if (! in_array($to, $allowed[$r->publication_status] ?? [], true)) {
            throw ValidationException::withMessages(['publication_status' => 'Invalid resource lifecycle transition.']);
        }$values = ['publication_status' => $to, 'approval_comments' => $comments];
        if ($to === 'approved') {
            $values += ['approved_by' => $u, 'approved_at' => now()];
        }if ($to === 'published') {
            $values += ['published_by' => $u, 'published_at' => now()];
        }if ($to === 'archived') {
            $values += ['archived_by' => $u, 'archived_at' => now()];
        }$r->update($values);

        return $r;
    }

    public function learnerResources(User $u, LearnerPortalAccessService $a)
    {
        $l = $a->learner($u);

        return LearningResource::current()->where('school_id', $u->school_id)->where('publication_status', 'published')->where('grade_id', $l->grade_id)->where(fn ($q) => $q->whereNull('stream_id')->orWhere('stream_id', $l->stream_id))->whereIn('visibility', ['assigned_class', 'grade', 'school'])->with('category', 'learningArea', 'grade', 'stream', 'currentVersion')->paginate(20);
    }

    public function bookmark(User $u, string $id, bool $add = true)
    {
        $r = LearningResource::current()->whereKey($id)->where('school_id', $u->school_id)->where('publication_status', 'published')->firstOrFail();
        if ($add) {
            DB::table('learning_resource_bookmarks')->insertOrIgnore(['id' => (string) Str::uuid(), 'school_id' => $u->school_id, 'resource_id' => $r->id, 'user_id' => $u->id, 'learner_id' => $u->learner?->id, 'created_at' => now()]);
        } else {
            DB::table('learning_resource_bookmarks')->where('user_id', $u->id)->where('resource_id', $r->id)->delete();
        }
    }

    public function rate(User $u, string $id, int $rating, ?string $review)
    {
        $r = LearningResource::current()->whereKey($id)->where('school_id', $u->school_id)->where('publication_status', 'published')->firstOrFail();
        $existing = DB::table('learning_resource_ratings')->where('user_id', $u->id)->where('resource_id', $r->id)->first();
        if ($existing) {
            DB::table('learning_resource_ratings')->where('id', $existing->id)->update(['rating' => $rating, 'review' => $review, 'updated_at' => now()]);
        } else {
            DB::table('learning_resource_ratings')->insert(['id' => (string) Str::uuid(), 'school_id' => $u->school_id, 'resource_id' => $r->id, 'user_id' => $u->id, 'learner_id' => $u->learner?->id, 'rating' => $rating, 'review' => $review, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
