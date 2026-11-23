<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Requests\PublicSchoolRegistrationRequest;
use App\Services\Homework\EnterpriseHomeworkAccess;
use App\Services\Platform\SchoolRegistrationRequestService;
use App\Services\StudentElectionSubscription\StudentElectionCommercialEvaluator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SchoolRegistrationRequestController extends BaseApiController
{
    public function __construct(private SchoolRegistrationRequestService $registrations) {}

    public function packages()
    {
        $packages = DB::table('subscription_packages')->where('active', true)
            ->orderBy('amount')->orderBy('package_name')
            ->get(['id', 'package_name', 'description', 'amount', 'duration_months']);
        $features = DB::table('package_features')
            ->join('subscription_features', 'subscription_features.id', '=', 'package_features.feature_id')
            ->whereIn('package_features.package_id', $packages->pluck('id'))
            ->where('subscription_features.active', true)
            ->orderBy('subscription_features.feature_name')
            ->get(['package_features.package_id', 'subscription_features.feature_name'])
            ->groupBy('package_id');

        return $this->success($packages->map(function ($package) use ($features) {
            $package->features = $features->get($package->id, collect())->pluck('feature_name')->values();
            $package->elections_included = StudentElectionCommercialEvaluator::includesElections($package->package_name);
            if ($package->elections_included && ! $package->features->contains(StudentElectionCommercialEvaluator::INCLUDED_FEATURE)) {
                $package->features->push(StudentElectionCommercialEvaluator::INCLUDED_FEATURE);
            }

            if (EnterpriseHomeworkAccess::includesHomework($package->package_name)
                && ! $package->features->contains(EnterpriseHomeworkAccess::INCLUDED_FEATURE)) {
                $package->features->push(EnterpriseHomeworkAccess::INCLUDED_FEATURE);
            }

            return $package;
        }));
    }

    public function store(PublicSchoolRegistrationRequest $request)
    {
        $this->registrations->submit($request->validated());

        return response()->json(['success' => true, 'message' => 'Registration requests are reviewed before accounts are created. An administrator invitation is sent only after approval.'], 202);
    }

    public function index(Request $request)
    {
        $data = $request->validate(['status' => 'sometimes|in:pending,approved,rejected', 'page' => 'sometimes|integer|min:1']);

        return $this->success($this->registrations->index($request->user(), $data['status'] ?? 'pending'));
    }

    public function approve(Request $request, string $registration)
    {
        return $this->success($this->registrations->review($request->user(), $registration, 'approve', null));
    }

    public function reject(Request $request, string $registration)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $reason = trim(strip_tags($data['reason']));
        validator(['reason' => $reason], ['reason' => 'required|string|max:500'])->validate();

        return $this->success($this->registrations->review($request->user(), $registration, 'reject', $reason));
    }
}
