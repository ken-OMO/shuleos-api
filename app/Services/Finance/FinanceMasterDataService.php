<?php

namespace App\Services\Finance;

use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceMasterDataService
{
    public function __construct(private FinanceMoney $money, private FinanceAuditService $audit) {}

    public function category(User $user, array $data, ?string $id = null): FeeCategory
    {
        $name = trim(preg_replace('/\s+/', ' ', $data['category_name']));
        $duplicate = FeeCategory::where('school_id', $user->school_id)->whereRaw('LOWER(category_name) = ?', [mb_strtolower($name)])->where('is_deleted', false)->when($id, fn ($query) => $query->whereKeyNot($id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['category_name' => 'Category name already exists.']);
        }
        if ($id) {
            $category = FeeCategory::whereKey($id)->where('school_id', $user->school_id)->where('is_deleted', false)->firstOrFail();
            abort_if($category->is_system && $category->category_name !== $name, 409, 'System category cannot be renamed.');
            $old = $category->only(['category_name', 'description', 'active']);
            $category->update(['category_name' => $name, 'description' => $data['description'] ?? null, 'active' => $data['active'] ?? $category->active, 'updated_at' => now()]);
            $this->audit->record($user, 'category_updated', 'fee_categories', $category->id, $old, $category->only(['category_name', 'description', 'active']));

            return $category;
        }
        $category = FeeCategory::create(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'category_name' => $name, 'description' => $data['description'] ?? null, 'is_system' => false, 'active' => true, 'is_deleted' => false]);
        $this->audit->record($user, 'category_created', 'fee_categories', $category->id);

        return $category;
    }

    public function deactivate(User $user, string $id): FeeCategory
    {
        $category = FeeCategory::whereKey($id)->where('school_id', $user->school_id)->where('is_deleted', false)->firstOrFail();
        abort_if($category->is_system, 409, 'System category cannot be deactivated.');
        $category->update(['active' => false, 'updated_at' => now()]);
        $this->audit->record($user, 'category_deactivated', 'fee_categories', $category->id);

        return $category;
    }

    public function structure(User $user, array $data, ?string $id = null): FeeStructure
    {
        $year = DB::table('academic_years')->where('id', $data['academic_year_id'])->where('school_id', $user->school_id)->first();
        $term = DB::table('terms')->where('id', $data['term_id'])->where('school_id', $user->school_id)->where('academic_year_id', $data['academic_year_id'])->first();
        $grade = DB::table('grades')->where('id', $data['grade_id'])->where('school_id', $user->school_id)->first();
        $category = FeeCategory::whereKey($data['fee_category_id'])->where('school_id', $user->school_id)->where('active', true)->where('is_deleted', false)->first();
        if (! $year || ! $term || ! $grade || ! $category) {
            throw ValidationException::withMessages(['scope' => 'Invalid same-school structure scope.']);
        }
        if (! empty($data['stream_id']) && ! DB::table('streams')->where('id', $data['stream_id'])->where('school_id', $user->school_id)->where('grade_id', $grade->id)->exists()) {
            throw ValidationException::withMessages(['stream_id' => 'Stream must belong to the selected grade.']);
        }
        $amount = $this->money->positive($data['amount']);
        $query = FeeStructure::where('school_id', $user->school_id)->where('academic_year_id', $year->id)->where('term_id', $term->id)->where('grade_id', $grade->id)->where('fee_category_id', $category->id)->whereIn('status', ['draft', 'approved', 'active'])->where('is_deleted', false)->when($data['stream_id'] ?? null, fn ($q, $stream) => $q->where('stream_id', $stream), fn ($q) => $q->whereNull('stream_id'))->when($id, fn ($q) => $q->whereKeyNot($id));
        if ($query->exists()) {
            throw ValidationException::withMessages(['structure' => 'An active structure already exists for this scope.']);
        }
        $values = collect($data)->only(['academic_year_id', 'term_id', 'grade_id', 'stream_id', 'fee_category_id', 'due_date', 'notes'])->all() + ['amount' => $amount];
        if ($id) {
            $structure = FeeStructure::whereKey($id)->where('school_id', $user->school_id)->where('status', 'draft')->firstOrFail();
            $structure->update($values + ['updated_at' => now()]);

            return $structure;
        }
        $structure = FeeStructure::create($values + ['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'status' => 'draft', 'active' => false, 'is_deleted' => false, 'created_by' => $user->id]);
        $this->audit->record($user, 'structure_created', 'fee_structures', $structure->id);

        return $structure;
    }

    public function transition(User $user, string $id, string $status): FeeStructure
    {
        $structure = FeeStructure::whereKey($id)->where('school_id', $user->school_id)->firstOrFail();
        $allowed = ['draft' => ['approved'], 'approved' => ['active'], 'active' => ['archived']];
        if (! in_array($status, $allowed[$structure->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'Invalid structure transition.']);
        }
        $values = ['status' => $status, 'active' => $status === 'active'];
        if ($status === 'approved') {
            $values += ['approved_by' => $user->id, 'approved_at' => now()];
        }
        if ($status === 'active') {
            $values += ['activated_at' => now()];
        }
        if ($status === 'archived') {
            $values += ['archived_at' => now()];
        }
        $structure->update($values);
        $this->audit->record($user, 'structure_'.$status, 'fee_structures', $structure->id);

        return $structure;
    }
}
