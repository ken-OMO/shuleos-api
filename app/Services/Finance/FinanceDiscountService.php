<?php

namespace App\Services\Finance;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceDiscountService
{
    private const TYPES = ['percentage', 'fixed_amount', 'full_waiver', 'scholarship', 'bursary', 'sibling_discount', 'staff_child_discount', 'hardship', 'promotional', 'other'];

    public function __construct(private FinanceMoney $money, private FinanceAuditService $audit) {}

    public function save(User $user, array $data, ?string $id = null): object
    {
        return DB::transaction(function () use ($user, $data, $id) {
            $creating = $id === null;
            $name = trim(preg_replace('/\s+/', ' ', $data['discount_name']));
            $type = $data['discount_type'];
            if (! in_array($type, self::TYPES, true)) {
                throw ValidationException::withMessages(['discount_type' => 'Unsupported discount type.']);
            }
            $value = $type === 'full_waiver' ? '100.00' : $this->money->positive($data['discount_value']);
            if ($type === 'percentage' && $this->money->minor($value) > 10000) {
                throw ValidationException::withMessages(['discount_value' => 'Percentage cannot exceed 100.']);
            }
            $maximum = isset($data['maximum_discount']) ? $this->money->positive($data['maximum_discount']) : null;
            if (! empty($data['effective_from']) && ! empty($data['effective_to']) && $data['effective_to'] < $data['effective_from']) {
                throw ValidationException::withMessages(['effective_to' => 'Effective end must not precede the start date.']);
            }
            $this->validateScope($user, $data);
            $duplicate = DB::table('fee_discounts')->where('school_id', $user->school_id)->whereRaw('LOWER(discount_name) = ?', [mb_strtolower($name)])->whereIn('status', ['draft', 'approved', 'active'])->where('is_deleted', false)->when($id, fn ($query) => $query->where('id', '!=', $id))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['discount_name' => 'An active discount with this name already exists.']);
            }
            $values = ['discount_name' => $name, 'discount_type' => $type, 'discount_value' => $value, 'maximum_discount' => $maximum, 'description' => $data['description'] ?? null, 'grade_id' => $data['grade_id'] ?? null, 'stream_id' => $data['stream_id'] ?? null, 'academic_year_id' => $data['academic_year_id'] ?? null, 'term_id' => $data['term_id'] ?? null, 'effective_from' => $data['effective_from'] ?? null, 'effective_to' => $data['effective_to'] ?? null, 'updated_at' => now()];
            if ($id) {
                $discount = DB::table('fee_discounts')->where('id', $id)->where('school_id', $user->school_id)->where('status', 'draft')->where('is_deleted', false)->lockForUpdate()->first();
                abort_unless($discount, 404);
                DB::table('fee_discounts')->where('id', $id)->update($values);
            } else {
                $id = (string) Str::uuid();
                DB::table('fee_discounts')->insert($values + ['id' => $id, 'school_id' => $user->school_id, 'status' => 'draft', 'revision' => 1, 'active' => false, 'is_deleted' => false, 'created_by' => $user->id, 'created_at' => now()]);
            }
            if (array_key_exists('fee_category_ids', $data)) {
                DB::table('fee_discount_categories')->where('discount_id', $id)->delete();
                foreach (array_unique($data['fee_category_ids'] ?? []) as $categoryId) {
                    abort_unless(DB::table('fee_categories')->where('id', $categoryId)->where('school_id', $user->school_id)->where('active', true)->where('is_deleted', false)->exists(), 422, 'Invalid fee category scope.');
                    DB::table('fee_discount_categories')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'discount_id' => $id, 'fee_category_id' => $categoryId, 'created_at' => now()]);
                }
            }
            $this->audit->record($user, $creating ? 'discount_created' : 'discount_saved', 'fee_discounts', $id);

            return $this->find($user, $id);
        });
    }

    public function transition(User $user, string $id, string $to): object
    {
        return DB::transaction(function () use ($user, $id, $to) {
            $discount = DB::table('fee_discounts')->where('id', $id)->where('school_id', $user->school_id)->where('is_deleted', false)->lockForUpdate()->first();
            abort_unless($discount, 404);
            $allowed = ['draft' => ['approved', 'cancelled'], 'approved' => ['active', 'cancelled'], 'active' => ['archived']];
            if (! in_array($to, $allowed[$discount->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'Invalid discount lifecycle transition.']);
            }
            $values = ['status' => $to, 'active' => $to === 'active', 'updated_at' => now()];
            if ($to === 'approved') {
                $values += ['approved_by' => $user->id, 'approved_at' => now()];
            } elseif ($to === 'active') {
                $values['activated_at'] = now();
            } elseif ($to === 'archived') {
                $values['archived_at'] = now();
            }
            DB::table('fee_discounts')->where('id', $id)->update($values);
            $this->audit->record($user, 'discount_'.$to, 'fee_discounts', $id);

            return $this->find($user, $id);
        });
    }

    public function find(User $user, string $id): object
    {
        $discount = DB::table('fee_discounts')->where('id', $id)->where('school_id', $user->school_id)->where('is_deleted', false)->first();
        abort_unless($discount, 404);
        $discount->fee_category_ids = DB::table('fee_discount_categories')->where('discount_id', $id)->pluck('fee_category_id');

        return $discount;
    }

    private function validateScope(User $user, array $data): void
    {
        foreach (['grade_id' => 'grades', 'academic_year_id' => 'academic_years'] as $field => $table) {
            if (! empty($data[$field]) && ! DB::table($table)->where('id', $data[$field])->where('school_id', $user->school_id)->exists()) {
                throw ValidationException::withMessages([$field => 'Scope must belong to the authenticated school.']);
            }
        }
        if (! empty($data['term_id']) && ! DB::table('terms')->where('id', $data['term_id'])->where('school_id', $user->school_id)->when($data['academic_year_id'] ?? null, fn ($query, $year) => $query->where('academic_year_id', $year))->exists()) {
            throw ValidationException::withMessages(['term_id' => 'Term scope is invalid.']);
        }
        if (! empty($data['stream_id']) && ! DB::table('streams')->where('id', $data['stream_id'])->where('school_id', $user->school_id)->when($data['grade_id'] ?? null, fn ($query, $grade) => $query->where('grade_id', $grade))->exists()) {
            throw ValidationException::withMessages(['stream_id' => 'Stream scope is invalid.']);
        }
    }
}
