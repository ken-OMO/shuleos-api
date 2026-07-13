<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\LearningResourceCategoryResource;
use App\Models\LearningResourceCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LearningResourceCategoryController extends BaseApiController
{
    public function index(Request $request)
    {
        abort_unless(in_array(auth()->user()->role?->role_name, ['Teacher', 'Learner', 'HOD', 'Principal', 'Deputy Principal', 'School Admin'], true), 403);

        return $this->success(LearningResourceCategoryResource::collection(LearningResourceCategory::current()->where('school_id', $this->school($request))->orderBy('name')->get()));
    }

    public function store(Request $request)
    {
        $data = $this->data($request);
        $this->uniqueName($this->school($request), $data['name']);
        $category = LearningResourceCategory::create($data + ['id' => (string) Str::uuid(), 'school_id' => $this->school($request), 'created_by' => auth()->id()]);

        return $this->created(new LearningResourceCategoryResource($category));
    }

    public function update(Request $request, string $category)
    {
        $model = $this->find($request, $category);
        $data = $this->data($request);
        $this->uniqueName($this->school($request), $data['name'], $model->id);
        $model->update($data);

        return $this->success(new LearningResourceCategoryResource($model->fresh()));
    }

    public function destroy(Request $request, string $category)
    {
        $model = $this->find($request, $category);
        if ($model->resources()->current()->exists()) {
            throw ValidationException::withMessages(['category' => 'Category has active resources and cannot be deleted.']);
        }
        $model->update(['active' => false, 'is_deleted' => true, 'deleted_at' => now(), 'deleted_by' => auth()->id()]);

        return $this->success(null);
    }

    private function data(Request $request): array
    {
        return $request->validate(['name' => 'required|string|max:255', 'code' => 'nullable|string|max:100', 'description' => 'nullable|string|max:2000', 'active' => 'sometimes|boolean']);
    }

    private function uniqueName(string $school, string $name, ?string $except = null): void
    {
        $exists = LearningResourceCategory::where('school_id', $school)->where('is_deleted', false)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->when($except, fn ($q) => $q->whereKeyNot($except))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => 'An active category with this name already exists.']);
        }
    }

    private function find(Request $request, string $id): LearningResourceCategory
    {
        return LearningResourceCategory::whereKey($id)->where('school_id', $this->school($request))->where('is_deleted', false)->firstOrFail();
    }

    private function school(Request $request): string
    {
        return (string) $request->attributes->get('tenant_school_id');
    }
}
