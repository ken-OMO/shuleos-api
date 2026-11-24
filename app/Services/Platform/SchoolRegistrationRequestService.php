<?php

namespace App\Services\Platform;

use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use App\Services\Administrator\AdministratorPortalAccessService;
use App\Services\StudentElectionSubscription\StudentElectionCommercialEvaluator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SchoolRegistrationRequestService
{
    public function __construct(
        private AdministratorPortalAccessService $access,
        private PlatformSchoolOnboardingService $onboarding,
        private AdministratorAuditService $audit,
    ) {}

    public function submit(array $data): void
    {
        // Do not disclose existing accounts or pending requests through this public endpoint.
        if (DB::table('users')->whereRaw('LOWER(email) = ?', [$data['admin']['email']])->exists()) {
            return;
        }
        DB::table('school_registration_requests')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'school_name' => $data['school_name'],
            'operating_mode' => $data['operating_mode'],
            'requested_package_id' => $data['package_id'] ?? null,
            'trial_boarding' => $data['trial_boarding'] ?? false,
            'trial_elections' => ($data['trial_elections'] ?? false) && ! StudentElectionCommercialEvaluator::enterprisePackages()
                ->where('id', $data['package_id'] ?? null)->exists(),
            'trial_transport' => $data['trial_transport'] ?? false,
            'admin_first_name' => $data['admin']['first_name'], 'admin_last_name' => $data['admin']['last_name'],
            'admin_email' => $data['admin']['email'], 'email' => $data['email'] ?? null,
            'mpesa_payer_phone' => $data['mpesa_payer_phone'] ?? null,
            'phone' => $data['phone'] ?? null, 'postal_address' => $data['postal_address'] ?? null,
            'physical_address' => $data['physical_address'] ?? null,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function index(User $actor, string $status)
    {
        $this->access->requirePlatform($actor, 'onboard_schools');

        return DB::table('school_registration_requests')->where('status', $status)
            ->select('id', 'school_name', 'operating_mode', 'admin_first_name', 'admin_last_name',
                'admin_email', 'email', 'phone', 'mpesa_payer_phone', 'postal_address', 'physical_address', 'status',
                'school_id', 'review_reason', 'created_at', 'reviewed_at',
                'requested_package_id', 'trial_boarding', 'trial_elections', 'trial_transport')
            ->addSelect(['requested_package_name' => DB::table('subscription_packages')->select('package_name')
                ->whereColumn('subscription_packages.id', 'school_registration_requests.requested_package_id')])
            ->orderBy('created_at')->orderBy('id')->paginate(25);
    }

    public function review(User $actor, string $id, string $action, ?string $reason): array
    {
        $this->access->requirePlatform($actor, 'onboard_schools');

        return DB::transaction(function () use ($actor, $id, $action, $reason) {
            $row = DB::table('school_registration_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $status = $action === 'approve' ? 'approved' : 'rejected';
            if ($row->status === $status) {
                return ['id' => $id, 'status' => $status, 'school_id' => $row->school_id];
            }
            abort_unless($row->status === 'pending', 409, 'This registration request has already been reviewed.');
            $schoolId = null;
            $adminId = null;
            if ($action === 'approve') {
                if (DB::table('users')->whereRaw('LOWER(email) = ?', [$row->admin_email])->exists()) {
                    throw ValidationException::withMessages(['request' => 'The administrator email is no longer available. Review this request with the applicant.']);
                }
                if ($row->requested_package_id !== null && ! DB::table('subscription_packages')
                    ->where('id', $row->requested_package_id)->where('active', true)->sharedLock()->first()) {
                    throw ValidationException::withMessages(['package_id' => 'The requested package is no longer available.']);
                }
                $result = $this->onboarding->onboard($actor, [
                    'school_name' => $row->school_name, 'operating_mode' => $row->operating_mode,
                    'email' => $row->email, 'phone' => $row->phone,
                    'postal_address' => $row->postal_address, 'physical_address' => $row->physical_address,
                    'admin' => ['first_name' => $row->admin_first_name, 'last_name' => $row->admin_last_name, 'email' => $row->admin_email],
                ]);
                $schoolId = $result['school']['id'];
                $adminId = $result['initial_admin']['id'];
            }
            DB::table('school_registration_requests')->where('id', $id)->update([
                'status' => $status, 'school_id' => $schoolId, 'reviewed_by' => $actor->id,
                'initial_admin_user_id' => $adminId, 'review_reason' => $reason, 'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record($actor, 'school_registration_'.$status, 'school_registration_requests', $id,
                ['status' => 'pending'], ['status' => $status, 'school_id' => $schoolId]);

            // Invitation credentials never leave the existing onboarding mail flow.
            return ['id' => $id, 'status' => $status, 'school_id' => $schoolId];
        });
    }
}
