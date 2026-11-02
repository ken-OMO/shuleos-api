<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\User;
use App\Services\Auth\AuthContextService;
use App\Services\School\LearnerActivationModeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceChargeEligibilityService
{
    public function select(
        object $learner,
        Collection $structures,
        string $yearId,
        string $termId
    ): Collection {
        $participating = DB::table('fee_charge_participations')
            ->where('school_id', $learner->school_id)
            ->where('learner_id', $learner->id)
            ->where('academic_year_id', $yearId)
            ->where('term_id', $termId)
            ->where('active', true)
            ->pluck('fee_category_id');

        return $structures->filter(fn ($structure) => ($structure->charge_target === 'all'
                || $structure->charge_target === $learner->mode_of_study)
            && (! $structure->requires_participation
                || $participating->contains($structure->fee_category_id))
        )->unique('fee_category_id')->values();
    }

    public function saveParticipation(User $user, array $data): object
    {
        abort_unless(
            $user->school_id
            && app(AuthContextService::class)->hasPermission($user, 'manage_fee_structures'),
            403
        );

        return DB::transaction(function () use ($user, $data) {
            app(LearnerActivationModeService::class)->lockSchool((string) $user->school_id);

            $learner = DB::table('learners')
                ->where('id', $data['learner_id'])
                ->where('school_id', $user->school_id)
                ->where('active', true)->where('is_deleted', false)
                ->lockForUpdate()->firstOrFail();

            DB::table('fee_categories')->where('id', $data['fee_category_id'])
                ->where('school_id', $user->school_id)
                ->where('active', true)->where('is_deleted', false)
                ->firstOrFail();

            abort_unless(
                DB::table('terms')->where('id', $data['term_id'])
                    ->where('school_id', $user->school_id)
                    ->where('academic_year_id', $data['academic_year_id'])->exists(),
                422,
                'Invalid academic year and term.'
            );

            $key = [
                'school_id' => $user->school_id,
                'learner_id' => $learner->id,
                'fee_category_id' => $data['fee_category_id'],
                'academic_year_id' => $data['academic_year_id'],
                'term_id' => $data['term_id'],
            ];

            $existing = DB::table('fee_charge_participations')->where($key)->first();

            if ($existing && (bool) $existing->active === (bool) $data['active']) {
                return $existing;
            }

            $values = [
                'active' => (bool) $data['active'],
                'updated_by' => $user->id,
                'updated_at' => now(),
            ];

            $id = $existing?->id ?? (string) Str::uuid();

            if ($existing) {
                DB::table('fee_charge_participations')->where('id', $id)->update($values);
            } else {
                DB::table('fee_charge_participations')->insert($key + $values + [
                    'id' => $id,
                    'created_at' => now(),
                ]);
            }

            app(FinanceAuditService::class)->record(
                $user,
                'charge_participation_updated',
                'fee_charge_participations',
                $id,
                ['active' => $existing ? (bool) $existing->active : null],
                ['active' => (bool) $data['active']]
            );

            return DB::table('fee_charge_participations')->where('id', $id)->first();
        });
    }
}
