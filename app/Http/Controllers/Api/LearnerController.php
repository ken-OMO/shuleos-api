<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\LearnerResource;
use App\Models\AuditLog;
use App\Models\Grade;
use App\Models\Learner;
use App\Models\Stream;
use App\Services\CoCurricular\CoCurricularPlacement;
use App\Services\School\LearnerActivationModeService;
use App\Services\School\SchoolBusinessWriteAccess;
use App\Services\School\SchoolOperatingModePolicy;
use App\Services\TeacherPortal\TeacherPortalAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LearnerController extends BaseCrudController
{
    private const MODULE = 'Learners';

    private const RELATIONS = [
        'grade',
        'stream',
    ];

    public function archived(Request $request)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $learners = $this->learnerQuery($request)
            ->with(self::RELATIONS)
            ->where('is_deleted', true)
            ->orderByDesc('deleted_at')
            ->orderBy('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return LearnerResource::collection($learners);
    }

    public function restore(Request $request, string $id)
    {
        $validated = $request->validate([
            'mode_of_study' => 'sometimes|required|in:day_scholar,boarder',
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $reason = trim($validated['reason']);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A restoration reason is required.',
            ]);
        }

        $learner = DB::transaction(function () use ($request, $id, $reason) {
            app(LearnerActivationModeService::class)
                ->lockSchool($this->schoolId($request));

            $learner = $this->learnerQuery($request)
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if (! $learner) {
                abort(404, 'Learner not found.');
            }

            if (! $learner->is_deleted) {
                throw ValidationException::withMessages([
                    'learner' => 'This learner is not archived.',
                ]);
            }

            // Reuse the existing identity; never recreate linked records.
            foreach (['admission_no', 'upi', 'assessment_no'] as $field) {
                $value = $learner->getAttribute($field);
                if (blank($value)) {
                    continue;
                }

                if ($this->learnerQuery($request)
                    ->where('is_deleted', false)
                    ->whereKeyNot($learner->id)
                    ->whereRaw('LOWER('.$field.') = ?', [mb_strtolower((string) $value)])
                    ->exists()) {
                    throw ValidationException::withMessages([
                        $field => 'Another current learner uses this identifier. Resolve the conflict before restoration.',
                    ]);
                }
            }

            $oldValues = $learner->toArray();

            if ($learner->lifecycle_status === 'active') {
                app(LearnerActivationModeService::class)
                    ->apply(
                        $learner,
                        $request->input('mode_of_study'),
                        (string) $request->user()->id,
                        $reason
                    );
            } elseif ($request->exists('mode_of_study')) {
                throw ValidationException::withMessages([
                    'mode_of_study' => 'Restore this inactive record first, then correct classification during reactivation.',
                ]);
            }

            // A terminal or unclassified lifecycle remains inactive.
            // Portal settings and linked user-account state are not rewritten.
            $learner->forceFill([
                'is_deleted' => false,
                'deleted_at' => null,
                'deleted_by' => null,
                'active' => $learner->lifecycle_status === 'active',
            ])->save();

            // Restoration and its audit must commit together.
            $audit = new AuditLog;
            $audit->forceFill([
                'school_id' => $learner->school_id,
                'user_id' => $request->user()->id,
                'module' => self::MODULE,
                'action' => 'Restore',
                'table_name' => $learner->getTable(),
                'record_id' => $learner->getKey(),
                'old_values' => $oldValues,
                'new_values' => $learner->fresh()->toArray(),
                'description' => 'Restored archived learner. Reason: '.$reason,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            if (! $audit->save()) {
                throw new \RuntimeException('The restoration audit could not be saved.');
            }

            return $learner->fresh()->load(self::RELATIONS);
        });

        return $this->success(
            new LearnerResource($learner),
            'Learner restored successfully.'
        );
    }

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

    private function classTeacherStreams(Request $request): array
    {
        $schoolId = $this->schoolId($request);
        $teacher = app(TeacherPortalAccessService::class)->teacher($request->user());
        $today = today('Africa/Nairobi')->toDateString();

        return DB::table('class_teachers as appointment')
            ->join('terms as term', 'term.id', '=', 'appointment.term_id')
            ->join('academic_years as year', 'year.id', '=', 'appointment.academic_year_id')
            ->where('appointment.school_id', $schoolId)->where('appointment.teacher_id', $teacher->id)
            ->where('appointment.active', true)->where('term.school_id', $schoolId)->where('year.school_id', $schoolId)
            ->whereColumn('term.academic_year_id', 'year.id')->where('term.active', true)->where('year.active', true)
            ->whereDate('term.end_date', '>=', $today)->whereDate('year.end_date', '>=', $today)
            ->where(fn ($query) => $query->whereNull('appointment.temporary_until')
                ->orWhere(fn ($cover) => $cover->whereDate('appointment.temporary_until', '>=', $today)
                    ->whereDate('term.start_date', '<=', $today)->whereDate('year.start_date', '<=', $today)))
            ->pluck('appointment.stream_id')->all();
    }

    public function classTeacherAdmissionOptions(Request $request)
    {
        $schoolId = $this->schoolId($request);
        $streams = Stream::query()->where('school_id', $schoolId)->where('active', true)
            ->whereIn('id', $this->classTeacherStreams($request))->orderBy('stream_name')->get(['id', 'stream_name', 'grade_id']);
        $grades = Grade::query()->where('school_id', $schoolId)->where('active', true)->whereNotNull('grade_number')
            ->whereIn('id', $streams->pluck('grade_id'))->orderBy('grade_order')->get(['id', 'grade_name', 'grade_number']);

        return $this->success(['grades' => $grades, 'streams' => $streams]);
    }

    public function classTeacherAdmit(Request $request, SchoolBusinessWriteAccess $access)
    {
        abort_if($request->attributes->get('client_supplied_school_id'), 422, 'School overrides are prohibited.');
        $request->validate(['stream_id' => 'required|uuid', 'co_curricular' => 'prohibited']);

        return $access->run($this->schoolId($request), function () use ($request) {
            abort_unless(in_array($request->input('stream_id'), $this->classTeacherStreams($request), true),
                403, 'You can only admit learners to a class covered by your appointment.');

            return $this->store($request);
        });
    }

    public function admissionOptions(Request $request)
    {
        $schoolId = $this->schoolId($request);
        $grades = Grade::query()
            ->where('school_id', $schoolId)
            ->where('active', true)
            ->whereNotNull('grade_number')
            ->orderBy('grade_order')
            ->get(['id', 'grade_name', 'grade_number']);

        $streams = Stream::query()
            ->where('school_id', $schoolId)
            ->where('active', true)
            ->whereIn('grade_id', $grades->modelKeys())
            ->orderBy('stream_name')
            ->get(['id', 'stream_name', 'grade_id']);

        return $this->success(['grades' => $grades, 'streams' => $streams]);
    }

    public function store(Request $request)
    {
        $schoolId = $this->schoolId($request);
        $admissionDate = today('Africa/Nairobi')->toDateString();

        $validated = $request->validate($this->admissionRules($admissionDate));
        $this->validateAdmission($schoolId, $validated);

        $learner = DB::transaction(function () use ($request, $schoolId, $validated, $admissionDate) {
            app(SchoolOperatingModePolicy::class)->assertLearnerModeAllowed($schoolId, $validated['mode_of_study']);

            return $this->createAdmission($request, $schoolId, $validated, $admissionDate);
        });

        $learner->load(self::RELATIONS);

        return $this->created(
            new LearnerResource($learner),
            'Learner admitted successfully.'
        );
    }

    public function classTeacherBulkAdmission(Request $request, SchoolBusinessWriteAccess $access)
    {
        abort_if($request->attributes->get('client_supplied_school_id'), 422, 'School overrides are prohibited.');
        $batch = $request->validate([
            'stream_id' => 'required|uuid',
            'preview' => 'required|boolean',
            'rows' => 'required|array|list|min:1|max:500',
            'rows.*' => 'required|array:row,admission_no,first_name,middle_name,last_name,gender,date_of_birth,mode_of_study,assessment_no,upi,kemis_no',
            'rows.*.row' => 'required|integer|min:2|max:1048576|distinct',
        ]);
        $schoolId = $this->schoolId($request);

        return $access->run($schoolId, function () use ($request, $batch, $schoolId) {
            abort_unless(in_array($batch['stream_id'], $this->classTeacherStreams($request), true),
                403, 'You can only admit learners to a class covered by your appointment.');
            $stream = Stream::query()->where('school_id', $schoolId)->where('active', true)->findOrFail($batch['stream_id']);
            $admissionDate = today('Africa/Nairobi')->toDateString();
            $rules = $this->admissionRules($admissionDate);
            $rules['gender'] = 'required|in:F,M';
            $rules['date_of_birth'] = ['required', 'date_format:Y-m-d', 'before_or_equal:'.$admissionDate];
            $validatedRows = [];
            $errors = [];
            $admissionNumbers = [];

            foreach ($batch['rows'] as $row) {
                try {
                    $validated = Validator::make($row + ['grade_id' => $stream->grade_id, 'stream_id' => $stream->id], $rules)->validate();
                    $this->validateAdmission($schoolId, $validated);
                    app(SchoolOperatingModePolicy::class)->assertLearnerModeAllowed($schoolId, $validated['mode_of_study']);
                    $number = $validated['admission_no'];
                    if (isset($admissionNumbers[$number])) {
                        throw ValidationException::withMessages(['admission_no' => 'Duplicate admission number in this workbook.']);
                    }
                    $admissionNumbers[$number] = true;
                    $validated['gender'] = $validated['gender'] === 'F' ? 'Female' : 'Male';
                    $validatedRows[] = $validated;
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $field => $messages) {
                        $errors['row_'.$row['row'].'.'.$field] = $messages;
                    }
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            if (! $batch['preview']) {
                foreach ($validatedRows as $validated) {
                    $this->createAdmission($request, $schoolId, $validated, $admissionDate);
                }
            }

            return $this->success(['count' => count($validatedRows)]);
        });
    }

    private function admissionRules(string $admissionDate): array
    {
        return [
            'co_curricular' => 'sometimes|array:club,house,society',
            'co_curricular.*' => 'nullable|uuid',
            'mode_of_study' => 'required|in:day_scholar,boarder',
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
            'kemis_no' => [
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
                'required',
                'string',
                'max:20',
            ],
            'date_of_birth' => [
                'required',
                'date',
                'before_or_equal:'.$admissionDate,
            ],
            'grade_id' => [
                'required',
                'uuid',
            ],
            'stream_id' => [
                'required',
                'uuid',
            ],
            'assessment_no' => [
                'nullable',
                'string',
                'max:50',
            ],
        ];

    }

    private function validateAdmission(string $schoolId, array $validated): void
    {
        $this->validatePlacement(
            $schoolId,
            (string) $validated['grade_id'],
            (string) $validated['stream_id']
        );

        $this->validateAssessmentNumber(
            $schoolId,
            (string) $validated['grade_id'],
            $validated['assessment_no'] ?? null
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
    }

    private function createAdmission(Request $request, string $schoolId, array $validated, string $admissionDate): Learner
    {
        $learner = Learner::query()
            ->withoutGlobalScopes()
            ->create([
                'id' => (string) Str::uuid(),
                'school_id' => $schoolId,
                'admission_no' => $validated['admission_no'],
                'upi' => $validated['upi'] ?? null,
                'kemis_no' => $validated['kemis_no'] ?? null,
                'first_name' => $validated['first_name'],
                'middle_name' => $validated['middle_name'] ?? null,
                'last_name' => $validated['last_name'],
                'gender' => $validated['gender'],
                'date_of_birth' => $validated['date_of_birth'],
                'grade_id' => $validated['grade_id'],
                'stream_id' => $validated['stream_id'],
                'admission_date' => $admissionDate,
                'assessment_no' => $validated['assessment_no'] ?? null,
                'active' => true,
                'is_deleted' => false,
                'deleted_at' => null,
                'deleted_by' => null,
                'user_id' => null,
                'portal_enabled' => false,
                'portal_activated_at' => null,
            ]);

        /*
         * lifecycle_status deliberately remains outside Learner::$fillable.
         * Admission owns initialization of the canonical lifecycle state.
         */
        $learner->mode_of_study = $validated['mode_of_study'];
        $learner->lifecycle_status = 'active';
        $learner->save();
        $learner->refresh();
        app(CoCurricularPlacement::class)->save($schoolId, $learner->id, $validated['co_curricular'] ?? []);

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

        if ($request->hasAny(['grade_id', 'stream_id'])) {
            throw ValidationException::withMessages([
                'placement' => [
                    'Grade and stream placement must be changed through the learner placement endpoint.',
                ],
            ]);
        }

        if ($request->has('mode_of_study')) {
            throw ValidationException::withMessages([
                'mode_of_study' => [
                    'Mode of study must be changed through the learner mode-of-study endpoint.',
                ],
            ]);
        }

        if ($request->hasAny([
            'active',
            'lifecycle_status',
        ])) {
            throw ValidationException::withMessages([
                'lifecycle_status' => [
                    'Learner lifecycle must be changed through the learner lifecycle endpoint.',
                ],
            ]);
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
            'kemis_no' => [
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

        $oldValues = $learner->toArray();

        DB::transaction(function () use (
            $request,
            $learner,
            $validated,
            $oldValues
        ) {
            $lockedLearner = $this->learnerQuery($request)
                ->whereKey($learner->id)
                ->where('is_deleted', false)
                ->lockForUpdate()
                ->firstOrFail();

            $this->validateAssessmentNumber(
                (string) $lockedLearner->school_id,
                (string) $lockedLearner->grade_id,
                array_key_exists('assessment_no', $validated)
                    ? $validated['assessment_no']
                    : $lockedLearner->assessment_no
            );

            $oldValues = $lockedLearner->toArray();
            $lockedLearner->update($validated);

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

    private function validateAssessmentNumber(
        string $schoolId,
        string $gradeId,
        ?string $assessmentNumber
    ): void {
        $grade = Grade::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereKey($gradeId)
            ->first();

        if ($grade && $grade->grade_number === null) {
            throw ValidationException::withMessages([
                'grade_id' => 'Classify this grade before admitting or updating its learners.',
            ]);
        }

        if ($grade?->requiresAssessmentNumber()
            && blank($assessmentNumber)) {
            throw ValidationException::withMessages([
                'assessment_no' => 'A school-provided assessment number is required for Grades 3–9.',
            ]);
        }
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
