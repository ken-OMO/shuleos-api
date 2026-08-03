<?php

namespace App\Services\LearnerPortal;

use App\Models\LearnerOfflineResource;
use App\Models\LearningResource;
use App\Models\User;
use App\Services\LearningResource\LearningResourceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LearnerOfflineResourceService
{
    public function __construct(private LearnerPortalAccessService $access, private LearningResourceService $resources) {}

    public function visible(User $user, string $id): LearningResource
    {
        $learner = $this->access->learner($user);

        return $this->resources->publishedForLearner($learner->school_id, $learner->grade_id, $learner->stream_id, ['assigned_class', 'grade', 'school'])->whereKey($id)->with('currentVersion')->firstOrFail();
    }

    public function mark(User $user, string $id): LearnerOfflineResource
    {
        $learner = $this->access->learner($user);
        $resource = $this->visible($user, $id);
        $count = LearnerOfflineResource::withoutGlobalScopes()->where('school_id', $user->school_id)->where('learner_id', $learner->id)->whereNull('revoked_at')->count();
        if ($count >= config('learner_portal_phase_two.offline_resource_limit', 50) && ! LearnerOfflineResource::withoutGlobalScopes()->where('learner_id', $learner->id)->where('resource_id', $id)->exists()) {
            throw ValidationException::withMessages(['resource' => 'The offline resource limit has been reached.']);
        }
        $termEnd = $resource->term_id ? DB::table('terms')->whereKey($resource->term_id)->value('end_date') : null;
        $offline = LearnerOfflineResource::withoutGlobalScopes()->firstOrNew([
            'learner_id' => $learner->id,
            'resource_id' => $resource->id,
        ]);
        if (! $offline->exists) {
            $offline->id = (string) Str::uuid();
            $offline->school_id = $user->school_id;
        }
        $offline->fill(['resource_version' => $resource->current_version_number, 'available_offline_at' => now(), 'expires_at' => $termEnd, 'revoked_at' => null])->save();

        return $offline;
    }

    public function remove(User $user, string $id): void
    {
        $learner = $this->access->learner($user);
        $updated = LearnerOfflineResource::withoutGlobalScopes()->where('school_id', $user->school_id)->where('learner_id', $learner->id)->where('resource_id', $id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        abort_unless($updated, 404);
    }

    public function index(User $user)
    {
        $learner = $this->access->learner($user);

        return LearnerOfflineResource::withoutGlobalScopes()->where('school_id', $user->school_id)->where('learner_id', $learner->id)->whereNull('revoked_at')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', now()))->whereHas('resource', fn ($query) => $query->where('publication_status', 'published')->where('is_deleted', false))->with('resource.currentVersion')->limit(config('learner_portal_phase_two.offline_resource_limit', 50))->get();
    }
}
