<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\LearningResourceAnalyticsResource;
use App\Http\Resources\LearningResourceResource;
use App\Http\Resources\LearningResourceVersionResource;
use App\Models\LearningResource;
use App\Services\LeadershipPortal\LeadershipPortalAccessService;
use App\Services\LearningResource\LearningResourceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LearningResourceAdminController extends BaseApiController
{
    public function __construct(private readonly LearningResourceService $service, private readonly LeadershipPortalAccessService $leadership) {}

    public function index(Request $request)
    {
        $query = $this->scoped($request)->with($this->service->relations());
        foreach (['learning_area_id', 'grade_id', 'stream_id', 'resource_type', 'category_id', 'publication_status'] as $field) {
            $query->when($request->filled($field), fn ($q) => $q->where($field, $request->input($field)));
        }

        return $this->success(LearningResourceResource::collection($query->paginate(20)));
    }

    public function show(Request $request, string $resource)
    {
        return $this->success(new LearningResourceResource($this->scoped($request)->whereKey($resource)->with($this->service->relations())->firstOrFail()));
    }

    public function versions(Request $request, string $resource)
    {
        $item = $this->scoped($request)->whereKey($resource)->firstOrFail();

        return $this->success(LearningResourceVersionResource::collection($item->versions()->with('creator')->get()->each->setAttribute('current_version_number', $item->current_version_number)));
    }

    public function transition(Request $request, string $resource, string $to)
    {
        $data = $request->validate(['comments' => 'nullable|string|max:4000']);

        return $this->success(new LearningResourceResource($this->service->transition($this->school($request), $resource, $to, auth()->user(), $data['comments'] ?? null)));
    }

    public function analytics(Request $request)
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $scope = $this->leadership->scope(auth()->user());
        $resources = DB::table('learning_resources')->where('school_id', $this->school($request))->where('is_deleted', false)->when(! $scope['whole_school'], fn ($q) => $q->whereIn('learning_area_id', $scope['learning_area_ids']));
        $logs = DB::table('learning_resource_access_logs')->where('school_id', $this->school($request))->whereIn('resource_id', (clone $resources)->select('id'))->when($request->from, fn ($q, $v) => $q->whereDate('occurred_at', '>=', $v))->when($request->to, fn ($q, $v) => $q->whereDate('occurred_at', '<=', $v));
        $group = fn (string $column) => (clone $resources)->selectRaw("{$column}, COUNT(*) total")->groupBy($column)->get();
        $ranked = function (string $action) use ($logs) {
            return (clone $logs)->where('action', $action)->join('learning_resources', 'learning_resources.id', '=', 'learning_resource_access_logs.resource_id')->selectRaw('resource_id, learning_resources.title, COUNT(*) total')->groupBy('resource_id', 'learning_resources.title')->orderByDesc('total')->limit(10)->get();
        };
        $data = [
            'total_resources' => (clone $resources)->count(),
            'published' => (clone $resources)->where('publication_status', 'published')->count(),
            'pending_review' => (clone $resources)->where('publication_status', 'pending_review')->count(),
            'rejected' => (clone $resources)->where('publication_status', 'rejected')->count(),
            'archived' => (clone $resources)->where('publication_status', 'archived')->count(),
            'by_learning_area' => $group('learning_area_id'), 'by_grade' => $group('grade_id'), 'by_type' => $group('resource_type'), 'by_category' => $group('category_id'), 'by_uploader' => $group('uploaded_by'),
            'access' => (clone $logs)->selectRaw('action, COUNT(*) total')->groupBy('action')->get(),
            'most_viewed' => $ranked('view'), 'most_downloaded' => $ranked('download'), 'most_opened' => $ranked('open_external_link'),
            'most_bookmarked' => DB::table('learning_resource_bookmarks')->where('school_id', $this->school($request))->whereIn('resource_id', (clone $resources)->select('id'))->selectRaw('resource_id, COUNT(*) total')->groupBy('resource_id')->orderByDesc('total')->limit(10)->get(),
            'highest_rated' => DB::table('learning_resource_ratings')->where('school_id', $this->school($request))->whereIn('resource_id', (clone $resources)->select('id'))->selectRaw('resource_id, AVG(rating) average, COUNT(*) count')->groupBy('resource_id')->orderByDesc('average')->limit(10)->get(),
            'no_access' => (clone $resources)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('learning_resource_access_logs')->whereColumn('learning_resource_access_logs.resource_id', 'learning_resources.id'))->select('id', 'title')->get(),
            'without_recent_access' => (clone $resources)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('learning_resource_access_logs')->whereColumn('learning_resource_access_logs.resource_id', 'learning_resources.id')->where('occurred_at', '>=', now()->subDays(config('learning_resources.inactive_access_days', 30))))->select('id', 'title')->get(),
            'teacher_contributions' => $group('uploaded_by'),
        ];

        return $this->success(new LearningResourceAnalyticsResource($data));
    }

    private function scoped(Request $request)
    {
        $scope = $this->leadership->scope(auth()->user());

        return LearningResource::current()->where('school_id', $this->school($request))->when(! $scope['whole_school'], fn ($q) => $q->whereIn('learning_area_id', $scope['learning_area_ids']));
    }

    private function school(Request $request): string
    {
        return (string) $request->attributes->get('tenant_school_id');
    }
}
