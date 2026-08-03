<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class LearningResourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $role = $user?->role?->role_name;
        $management = in_array($role, ['Teacher', 'HOD', 'Principal', 'Deputy Principal', 'School Admin'], true);
        $ratings = DB::table('learning_resource_ratings')->where('resource_id', $this->id)->selectRaw('AVG(rating) average, COUNT(*) count')->first();
        $access = DB::table('learning_resource_access_logs')->where('resource_id', $this->id)->selectRaw('action, COUNT(*) total')->groupBy('action')->pluck('total', 'action');

        return [
            'id' => $this->id, 'title' => $this->title, 'description' => $this->description, 'resource_type' => $this->resource_type, 'source_type' => $this->source_type,
            'is_external_link' => $this->source_type === 'external_link', 'external_url' => $this->source_type === 'external_link' ? $this->external_url : null,
            'visibility' => $this->visibility, 'publication_status' => $this->when($management, $this->publication_status), 'strand' => $this->strand, 'sub_strand' => $this->sub_strand,
            'category' => new LearningResourceCategoryResource($this->whenLoaded('category')), 'learning_area' => $this->whenLoaded('learningArea'), 'grade' => $this->whenLoaded('grade'), 'stream' => $this->whenLoaded('stream'),
            'current_version_number' => $this->current_version_number, 'current_version' => new LearningResourceVersionResource($this->whenLoaded('currentVersion')),
            'author' => $this->whenLoaded('uploader', fn () => ['id' => $this->uploader->id, 'name' => trim($this->uploader->first_name.' '.$this->uploader->last_name)]),
            'rating' => ['average' => $ratings?->average !== null ? round((float) $ratings->average, 2) : null, 'count' => (int) ($ratings?->count ?? 0)],
            'bookmarks_count' => DB::table('learning_resource_bookmarks')->where('resource_id', $this->id)->count(),
            'is_bookmarked' => $user ? DB::table('learning_resource_bookmarks')->where('resource_id', $this->id)->where('user_id', $user->id)->exists() : false,
            'access_counts' => ['views' => (int) ($access['view'] ?? 0), 'downloads' => (int) ($access['download'] ?? 0), 'external_opens' => (int) ($access['open_external_link'] ?? 0)],
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
