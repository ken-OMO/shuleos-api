<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\LearningResourceResource;
use App\Models\LearningResource;
use App\Services\LearningResource\LearningResourceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LearningResourceAdminController extends BaseApiController
{
    public function __construct(private readonly LearningResourceService $s) {}

    private function school(Request $r): string
    {
        return (string) $r->attributes->get('tenant_school_id');
    }

    public function index(Request $r)
    {
        return $this->success(LearningResourceResource::collection(LearningResource::current()->where('school_id', $this->school($r))->with('currentVersion', 'category', 'learningArea', 'grade', 'stream')->paginate(20)));
    }

    public function transition(Request $r, string $resource, string $to)
    {
        $v = $r->validate(['comments' => 'nullable|string']);

        return $this->success(new LearningResourceResource($this->s->transition($this->school($r), $resource, $to, auth()->id(), $v['comments'] ?? null)));
    }

    public function analytics(Request $r)
    {
        $s = $this->school($r);
        $q = DB::table('learning_resources')->where('school_id', $s)->where('is_deleted', false);

        return $this->success(['total' => $q->count(), 'published' => (clone $q)->where('publication_status', 'published')->count(), 'pending_review' => (clone $q)->where('publication_status', 'pending_review')->count(), 'archived' => (clone $q)->where('publication_status', 'archived')->count(), 'by_type' => (clone $q)->selectRaw('resource_type,COUNT(*) total')->groupBy('resource_type')->get(), 'access' => DB::table('learning_resource_access_logs')->where('school_id', $s)->selectRaw('action,COUNT(*) total')->groupBy('action')->get()]);
    }
}
