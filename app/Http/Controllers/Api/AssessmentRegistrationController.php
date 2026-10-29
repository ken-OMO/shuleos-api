<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Requests\Assessment\StoreAssessmentRegistrationRequest;
use App\Http\Requests\Assessment\UpdateAssessmentRegistrationRequest;
use App\Http\Resources\AssessmentRegistrationResource;
use App\Models\AssessmentRegistration;
use App\Services\Assessment\AssessmentRegistrationService;
use App\Services\Assessment\AssessmentTenantContext;
use Illuminate\Http\Request;

class AssessmentRegistrationController extends BaseCrudController
{
    private const MODULE = 'Assessment Registrations';

    private const RELATIONS = ['learner'];

    public function __construct(private readonly AssessmentRegistrationService $service) {}

    public function index(Request $r)
    {
        $v = $r->validate(['assessment_year' => 'sometimes|integer|min:2000|max:2100', 'assessment_type' => 'sometimes|string|max:255', 'status' => 'sometimes|in:pending,registered,verified,rejected,cancelled', 'learner_id' => 'sometimes|uuid', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $q = AssessmentRegistration::with(self::RELATIONS)->current()->where('school_id', $this->school($r));
        foreach (['assessment_year', 'assessment_type', 'status', 'learner_id'] as $f) {
            $q->when(isset($v[$f]), fn ($x) => $x->where($f, $v[$f]));
        }

        return $this->success(AssessmentRegistrationResource::collection($q->orderByDesc('created_at')->paginate($v['per_page'] ?? 20)), 'Assessment registrations retrieved successfully.');
    }

    public function show(Request $r, string $id)
    {
        $x = $this->q($r)->with(self::RELATIONS)->find($id);

        return $x ? $this->success(new AssessmentRegistrationResource($x), 'Assessment registration retrieved successfully.') : $this->notFound('Assessment registration not found.');
    }

    public function store(StoreAssessmentRegistrationRequest $r)
    {
        $v = $r->validated();
        $x = $this->service->create($v, $this->school($r, $v), auth()->id());
        $this->audit($r, self::MODULE, 'Create', $x, null, $x->toArray(), 'Created assessment registration.');

        return $this->created(new AssessmentRegistrationResource($x->load(self::RELATIONS)), 'Assessment registration created successfully.');
    }

    public function update(UpdateAssessmentRegistrationRequest $r, string $id)
    {
        $x = $this->q($r)->find($id);
        if (! $x) {
            return $this->notFound('Assessment registration not found.');
        }$v = $r->validated();
        $x = $this->service->update($this->school($r), $id, $v);

        return $this->success(new AssessmentRegistrationResource($x->refresh()->load(self::RELATIONS)), 'Assessment registration updated successfully.');
    }

    public function destroy(Request $r, string $id)
    {
        $x = $this->q($r)->find($id);
        if (! $x) {
            return $this->notFound('Assessment registration not found.');
        }
        $this->service->delete($this->school($r), $id, auth()->id());

        return $this->success(null, 'Assessment registration deleted successfully.');
    }

    private function q(Request $r)
    {
        return AssessmentRegistration::current()->where('school_id', $this->school($r));
    }

    private function school(Request $r, array $v = []): string
    {
        return AssessmentTenantContext::schoolId($r);
    }
}
