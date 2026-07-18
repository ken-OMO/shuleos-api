<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\AdvancedCommunicationAnalyticsResource;
use App\Http\Resources\CommunicationBrandingResource;
use App\Http\Resources\CommunicationPreferenceResource;
use App\Http\Resources\ContactHealthResource;
use App\Http\Resources\ProviderHealthResource;
use App\Http\Resources\RecurringCommunicationResource;
use App\Http\Resources\SmsCreditTransactionResource;
use App\Http\Resources\SmsWalletResource;
use App\Services\Communication\AdvancedCommunicationAnalyticsService;
use App\Services\Communication\CommunicationBrandingService;
use App\Services\Communication\CommunicationPreferenceService;
use App\Services\Communication\ContactHealthService;
use App\Services\Communication\EmergencyCommunicationService;
use App\Services\Communication\ProviderHealthService;
use App\Services\Communication\RecurringCommunicationService;
use App\Services\Communication\SmsWalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunicationPhaseTwoController extends BaseApiController
{
    public function __construct(private ProviderHealthService $providers, private ContactHealthService $contacts, private SmsWalletService $wallets, private CommunicationPreferenceService $preferences, private CommunicationBrandingService $branding, private RecurringCommunicationService $recurring, private EmergencyCommunicationService $emergency, private AdvancedCommunicationAnalyticsService $analytics) {}

    public function providerHealth()
    {
        return $this->success(new ProviderHealthResource($this->providers->status()));
    }

    public function contactHealth()
    {
        return $this->success(ContactHealthResource::collection(DB::table('communication_contact_health')->where('school_id', auth()->user()->school_id)->latest('updated_at')->paginate(30)));
    }

    public function restoreContact(Request $request, string $contact)
    {
        return $this->success(new ContactHealthResource($this->contacts->restore(auth()->user(), $contact, $request->validate(['reason' => 'required|string|max:1000'])['reason'])));
    }

    public function wallet()
    {
        return $this->success(new SmsWalletResource($this->wallets->wallet(auth()->user()->school_id)));
    }

    public function walletTransactions()
    {
        return $this->success(SmsCreditTransactionResource::collection(DB::table('sms_credit_transactions')->where('school_id', auth()->user()->school_id)->latest('created_at')->paginate(50)));
    }

    public function adjustWallet(Request $request)
    {
        $data = $request->validate(['credits' => 'required|integer|not_in:0', 'reason' => 'required|string|max:1000']);

        return $this->success(new SmsWalletResource($this->wallets->adjust(auth()->user(), $data['credits'], $data['reason'])));
    }

    public function preferences()
    {
        return $this->success(new CommunicationPreferenceResource($this->preferences->get(auth()->user())));
    }

    public function updatePreferences(Request $request)
    {
        $data = $request->validate(['email_enabled' => 'sometimes|boolean', 'sms_enabled' => 'sometimes|boolean', 'in_app_enabled' => 'sometimes|boolean', 'digest_frequency' => 'sometimes|in:immediate,daily,weekly,never', 'quiet_hours_start' => 'nullable|date_format:H:i', 'quiet_hours_end' => 'nullable|date_format:H:i', 'timezone' => 'sometimes|timezone', 'language' => 'sometimes|string|max:10', 'emergency_override' => 'sometimes|boolean', 'marketing_opt_out' => 'sometimes|boolean']);

        return $this->success(new CommunicationPreferenceResource($this->preferences->update(auth()->user(), $data)));
    }

    public function branding()
    {
        return $this->success(new CommunicationBrandingResource($this->branding->get(auth()->user()->school_id)));
    }

    public function updateBranding(Request $request)
    {
        $data = $request->validate(['sender_display_name' => 'nullable|string|max:100', 'reply_to_email' => 'nullable|email:rfc|max:255', 'logo_reference' => 'nullable|string|max:500', 'footer_text' => 'nullable|string|max:2000', 'address' => 'nullable|string|max:500', 'phone' => 'nullable|string|max:40', 'website' => 'nullable|url|max:500', 'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'secondary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/']]);

        return $this->success(new CommunicationBrandingResource($this->branding->update(auth()->user(), $data)));
    }

    public function recurringIndex()
    {
        return $this->success(RecurringCommunicationResource::collection(DB::table('recurring_communication_schedules')->where('school_id', auth()->user()->school_id)->latest('created_at')->paginate(30)));
    }

    public function recurringStore(Request $request)
    {
        return $this->created(new RecurringCommunicationResource($this->recurring->create(auth()->user(), $request->validate($this->recurringRules()))));
    }

    public function recurringUpdate(Request $request, string $schedule)
    {
        return $this->success(new RecurringCommunicationResource($this->recurring->update(auth()->user(), $schedule, $request->validate(['maximum_occurrences' => 'sometimes|nullable|integer|min:1|max:365', 'ends_at' => 'sometimes|nullable|date', 'missed_run_policy' => 'sometimes|in:skip,send_once']))));
    }

    public function recurringAction(string $schedule, string $action)
    {
        return $this->success(new RecurringCommunicationResource($this->recurring->transition(auth()->user(), $schedule, $action)));
    }

    public function emergencyPreview(Request $request)
    {
        return $this->success($this->emergency->preview(auth()->user(), $request->validate($this->emergencyRules())));
    }

    public function emergencySend(Request $request)
    {
        $data = $request->validate($this->emergencyRules() + ['confirmation_token' => 'required|string|size:64']);
        $token = $data['confirmation_token'];
        unset($data['confirmation_token']);

        return $this->created($this->emergency->send(auth()->user(), $data, $token));
    }

    public function analytics()
    {
        return $this->success(new AdvancedCommunicationAnalyticsResource($this->analytics->summary(auth()->user())));
    }

    private function recurringRules(): array
    {
        return ['communication_id' => 'required|uuid', 'frequency' => 'required|in:daily,weekly,monthly,selected_weekdays', 'selected_weekdays' => 'required_if:frequency,selected_weekdays|array', 'selected_weekdays.*' => 'integer|between:1,7', 'maximum_occurrences' => 'nullable|integer|min:1|max:365', 'starts_at' => 'required|date', 'ends_at' => 'nullable|date|after:starts_at', 'timezone' => 'required|timezone', 'missed_run_policy' => 'sometimes|in:skip,send_once'];
    }

    private function emergencyRules(): array
    {
        return ['subject' => 'required|string|max:255', 'body' => 'required|string|max:2000', 'reason' => 'required|string|max:1000', 'emergency_category' => 'required|string|max:80', 'attempt_sms' => 'sometimes|boolean', 'targets' => 'required|array|min:1', 'targets.*.target_type' => 'required|string|max:60', 'targets.*.options' => 'sometimes|array'];
    }
}
