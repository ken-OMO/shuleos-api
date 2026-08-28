<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\LearnerResource;
use App\Models\Grade;
use App\Models\Learner;
use App\Models\Stream;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LearnerController extends BaseCrudController
{
    private const MODULE = 'Learners';

    private const RELATIONS = [
        'grade',
        'stream',
    ];

    public function index(Request $request)
    {
        $learners = $this->learnerQuery($request)
            ->with(self::RELATIONS)
            ->where('is_deleted', false)
            ->orderByDesc('created_at')
            ->get();

        return $this->success(
            LearnerResource::collection($learners),
            'Learners retrieved successfully.'
        );
    }

    public function show(Request $request, string $id)
    {
        $learner = $this->learnerQuery($request)
            ->with(self::RELATIONS)
            ->where('is_deleted', false)
            ->whereKey($id)
            ->first();

        if (! $learner) {
            return $this->notFound(
                'Learner not found.'
            );
        }

        return $this->success(
            new LearnerResource($learner),
            'Learner retrieved successfully.'
        );
    }

    public function store(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $validated = $request->validate([
            'admission_no' => [
                'required',
                'string',
                'max:50',
            ],
            'upi' => [
                'nullable',
                'string',
                'max:50',
            ],
            'first_name' => [
                'required',
                'string',
                'max:100',
            ],
            'middle_name' => [
                'nullable',
                'string',
                'max:100',
            ],
            'last_name' => [
                'required',
                'string',
                'max:100',
            ],
            'gender' => [
                'nullable',
                'string',
                'max:20',
            ],
            'date_of_birth' => [
                'nullable',
                'date',
                'before_or_equal:today',
            ],
            'grade_id' => [
                'required',
                'uuid',
            ],
            'stream_id' => [
                'required',
                'uuid',
            ],
            'admission_date' => [
                'nullable',
                'date',
            ],
            'assessment_no' => [
                'nullable',
                'string',
                'max:50',
            ],
        ]);

        $this->validatePlacement(
            $schoolId,
            (string) $validated['grade_id'],
            (string) $validated['stream_id']
        );

        $duplicate = Learner::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('admission_no', $validated['admission_no'])
            ->where('is_deleted', false)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'admission_no' => [
                    'The admission number has already been taken.',
                ],
            ]);
        }

        $learner = DB::transaction(function () use (
            $request,
            $schoolId,
            $validated
        ) {
            $learner = Learner::query()
                ->withoutGlobalScopes()
                ->create([
                    'id' => (string) Str::uuid(),
                    'school_id' => $schoolId,
                    'admission_no' => $validated['admission_no'],
                    'upi' => $validated['upi'] ?? null,
                    'first_name' => $validated['first_name'],
                    'middle_name' => $validated['middle_name'] ?? null,
                    'last_name' => $validated['last_name'],
                    'gender' => $validated['gender'] ?? null,
                    'date_of_birth' => $validated['date_of_birth'] ?? null,
                    'grade_id' => $validated['grade_id'],
                    'stream_id' => $validated['stream_id'],
                    'admission_date' => $validated['admission_date'] ?? null,
                    'assessment_no' => $validated['assessment_no'] ?? null,
                    'active' => true,
                    'is_deleted' => false,
                    'deleted_at' => null,
                    'deleted_by' => null,
                    'user_id' => null,
                    'portal_enabled' => false,
                    'portal_activated_at' => null,
                ]);

            $this->audit(
                request: $request,
                module: self::MODULE,
                action: 'Create',
                model: $learner,
                oldValues: null,
                newValues: $learner->toArray(),
                description: 'Admitted learner.'
            );

            return $learner;
        });

        $learner->load(self::RELATIONS);

        return $this->created(
            new LearnerResource($learner),
            'Learner admitted successfully.'
        );
    }

    public function update(Request $request, string $id)
    {
        $schoolId = $this->schoolId($request);

        $learner = $this->learnerQuery($request)
            ->where('is_deleted', false)
            ->whereKey($id)
            ->first();

        if (! $learner) {
            return $this->notFound(
                'Learner not found.'
            );
        }

        $validated = $request->validate([
            'admission_no' => [
                'sometimes',
                'required',
                'string',
                'max:50',
            ],
            'upi' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
            ],
            'first_name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
            ],
            'middle_name' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],
            'last_name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
            ],
            'gender' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
            ],
            'date_of_birth' => [
                'sometimes',
                'nullable',
                'date',
                'before_or_equal:today',
            ],
            'grade_id' => [
                'sometimes',
                'required',
                'uuid',
            ],
            'stream_id' => [
                'sometimes',
                'required',
                'uuid',
            ],
            'admission_date' => [
                'sometimes',
                'nullable',
                'date',
            ],
            'assessment_no' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
            ],
        ]);

        if (array_key_exists('admission_no', $validated)) {
            $duplicate = Learner::query()
                ->withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('admission_no', $validated['admission_no'])
                ->where('is_deleted', false)
                ->whereKeyNot($learner->id)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'admission_no' => [
                        'The admission number has already been taken.',
                    ],
                ]);
            }
        }

        if (
            array_key_exists('grade_id', $validated)
            || array_key_exists('stream_id', $validated)
        ) {
            $effectiveGradeId = (string) (
                $validated['grade_id']
                ?? $learner->grade_id
            );

            $effectiveStreamId = (string) (
                $validated['stream_id']
                ?? $learner->stream_id
            );

            $this->validatePlacement(
                $schoolId,
                $effectiveGradeId,
                $effectiveStreamId
            );
        }

        $oldValues = $learner->toArray();

        DB::transaction(function () use (
            $request,
            $learner,
            $validated,
            $oldValues
        ) {
            $learner->update($validated);

            $learner->refresh();

            $this->audit(
                request: $request,
                module: self::MODULE,
                action: 'Update',
                model: $learner,
                oldValues: $oldValues,
                newValues: $learner->toArray(),
                description: 'Updated learner profile.'
            );
        });

        $learner->load(self::RELATIONS);

        return $this->success(
            new LearnerResource($learner),
            'Learner updated successfully.'
        );
    }

    public function destroy(Request $request, string $id)
    {
        $learner = $this->learnerQuery($request)
            ->where('is_deleted', false)
            ->whereKey($id)
            ->first();

        if (! $learner) {
            return $this->notFound(
                'Learner not found.'
            );
        }

        $oldValues = $learner->toArray();

        DB::transaction(function () use (
            $request,
            $learner,
            $oldValues
        ) {
            /*
             * TenantModel::performDeleteOnModel() performs the
             * learner soft-delete and records deleted_by from
             * the authenticated user.
             */
            $learner->delete();

            $this->audit(
                request: $request,
                module: self::MODULE,
                action: 'Delete',
                model: $learner,
                oldValues: $oldValues,
                newValues: $learner->toArray(),
                description: 'Soft deleted learner.'
            );
        });

        return $this->success(
            null,
            'Learner deleted successfully.'
        );
    }

    private function validatePlacement(
        string $schoolId,
        string $gradeId,
        string $streamId
    ): void {
        $gradeExists = Grade::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereKey($gradeId)
            ->where('active', true)
            ->exists();

        if (! $gradeExists) {
            throw ValidationException::withMessages([
                'grade_id' => [
                    'The selected grade is invalid.',
                ],
            ]);
        }

        $streamExists = Stream::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('grade_id', $gradeId)
            ->whereKey($streamId)
            ->where('active', true)
            ->exists();

        if (! $streamExists) {
            throw ValidationException::withMessages([
                'stream_id' => [
                    'The selected stream is invalid.',
                ],
            ]);
        }
    }

    private function learnerQuery(Request $request): Builder
    {
        return Learner::query()
            ->withoutGlobalScopes()
            ->where(
                'school_id',
                $this->schoolId($request)
            );
    }

    private function schoolId(Request $request): string
    {
        $user = $request->user();

        if (! $user || blank($user->school_id)) {
            abort(403, 'School context not found.');
        }

        $schoolId = (string) $user->school_id;

        $tenantSchoolId = $request->attributes->get(
            'tenant_school_id'
        );

        if (
            blank($tenantSchoolId)
            || (string) $tenantSchoolId !== $schoolId
        ) {
            abort(403, 'School context not found.');
        }

        return $schoolId;
    }
}
