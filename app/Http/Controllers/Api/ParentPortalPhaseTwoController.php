<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\ParentAnalyticsResource;
use App\Http\Resources\ParentAppointmentResource;
use App\Http\Resources\ParentAttachmentResource;
use App\Http\Resources\ParentConsentResource;
use App\Http\Resources\ParentConsentResponseResource;
use App\Http\Resources\ParentConversationResource;
use App\Http\Resources\ParentLearnerProgressResource;
use App\Http\Resources\ParentMessageResource;
use App\Http\Resources\ParentPaymentAttemptResource;
use App\Http\Resources\ParentPaymentPreviewResource;
use App\Http\Resources\ParentPaymentReceiptResource;
use App\Http\Resources\ParentPhaseTwoDashboardResource;
use App\Http\Resources\ParentPushDeliveryResource;
use App\Http\Resources\ParentSyncConflictResource;
use App\Http\Resources\ParentSyncStatusResource;
use App\Http\Resources\ParentTaskResource;
use App\Models\Payment;
use App\Services\Finance\FinanceReceiptService;
use App\Services\ParentPortal\ParentDeviceService;
use App\Services\ParentPortal\ParentLearnerProgressService;
use App\Services\ParentPortal\ParentPaymentService;
use App\Services\ParentPortal\ParentPhaseTwoDashboardService;
use App\Services\ParentPortal\ParentPhaseTwoWorkflowService;
use App\Services\ParentPortal\ParentPortalAccessService;
use App\Services\ParentPortal\ParentPortalAnalyticsService;
use App\Services\ParentPortal\ParentPortalAttachmentService;
use App\Services\ParentPortal\ParentPushService;
use App\Services\ParentPortal\ParentSyncService;
use App\Services\ParentPortal\ParentTaskService;
use Illuminate\Http\Request;

class ParentPortalPhaseTwoController extends BaseApiController
{
    public function __construct(
        private ParentPaymentService $payments,
        private ParentPhaseTwoWorkflowService $workflows,
        private ParentLearnerProgressService $progress,
        private ParentTaskService $tasks,
        private ParentSyncService $sync,
        private ParentPortalAttachmentService $attachments,
        private ParentPushService $push,
        private ParentDeviceService $devices,
        private ParentPortalAnalyticsService $analytics,
        private ParentPhaseTwoDashboardService $dashboard,
        private ParentPortalAccessService $access,
        private FinanceReceiptService $receipts,
    ) {}

    private function user()
    {
        return auth()->user();
    }

    public function dashboard(Request $request)
    {
        return $this->success(new ParentPhaseTwoDashboardResource($this->dashboard->dashboard($this->user(), $request->query('learner_id'))));
    }

    public function tasks()
    {
        return $this->success(ParentTaskResource::collection($this->tasks->tasks($this->user())));
    }

    public function analytics()
    {
        return $this->success(new ParentAnalyticsResource($this->analytics->analytics($this->user())));
    }

    public function providerHealth()
    {
        return $this->success($this->payments->health($this->user()));
    }

    public function paymentPreview(Request $request, string $learner)
    {
        $data = $request->validate(['invoice_id' => ['nullable', 'uuid'], 'amount' => ['required', 'decimal:0,2'], 'phone' => ['nullable', 'string', 'max:20']]);

        return $this->success(new ParentPaymentPreviewResource($this->payments->preview($this->user(), $learner, $data)));
    }

    public function paymentInitiate(Request $request, string $learner)
    {
        $data = $request->validate(['invoice_id' => ['nullable', 'uuid'], 'amount' => ['required', 'decimal:0,2'], 'phone' => ['nullable', 'string', 'max:20'], 'idempotency_key' => ['required', 'uuid']]);

        return $this->success(new ParentPaymentAttemptResource($this->payments->initiate($this->user(), $learner, $data)));
    }

    public function payments(?string $learner = null)
    {
        return $this->success(ParentPaymentAttemptResource::collection($this->payments->index($this->user(), $learner)));
    }

    public function payment(string $paymentAttempt)
    {
        return $this->success(new ParentPaymentAttemptResource($this->payments->owned($this->user(), $paymentAttempt)));
    }

    public function financePayment(string $learner, string $payment)
    {
        $this->access->requireLinkedLearner($this->user(), $learner);
        $item = Payment::withoutGlobalScopes()->whereKey($payment)->where('school_id', $this->user()->school_id)->where('learner_id', $learner)->firstOrFail();

        return $this->success(new ParentPaymentReceiptResource($this->receipts->receipt($this->user(), $item->id)));
    }

    public function downloadReceipt(string $learner, string $payment)
    {
        $this->access->requireLinkedLearner($this->user(), $learner);
        $item = Payment::withoutGlobalScopes()->whereKey($payment)->where('school_id', $this->user()->school_id)->where('learner_id', $learner)->where('payment_status', 'confirmed')->firstOrFail();
        $receipt = $this->receipts->receipt($this->user(), $item->id);

        return response(json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="receipt-'.$item->receipt_number.'.json"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function cancelPayment(string $paymentAttempt)
    {
        return $this->success(new ParentPaymentAttemptResource($this->payments->cancel($this->user(), $paymentAttempt)));
    }

    public function conversations()
    {
        return $this->success(ParentConversationResource::collection($this->workflows->conversations($this->user())));
    }

    public function createConversation(Request $request)
    {
        $data = $request->validate(['learner_id' => ['required', 'uuid'], 'conversation_type' => ['required', 'string'], 'subject' => ['required', 'string', 'max:160'], 'message' => ['nullable', 'string', 'max:4000']]);

        return $this->success(new ParentConversationResource($this->workflows->createConversation($this->user(), $data)));
    }

    public function conversation(string $conversation)
    {
        return $this->success(new ParentConversationResource($this->workflows->conversation($this->user(), $conversation)));
    }

    public function messages(string $conversation)
    {
        return $this->success(ParentMessageResource::collection($this->workflows->messages($this->user(), $conversation)));
    }

    public function sendMessage(Request $request, string $conversation)
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:4000']]);

        return $this->success(new ParentMessageResource($this->workflows->addMessage($this->user(), $conversation, $data['message'])));
    }

    public function closeConversation(string $conversation)
    {
        return $this->success(new ParentConversationResource($this->workflows->closeConversation($this->user(), $conversation)));
    }

    public function consents()
    {
        return $this->success(ParentConsentResource::collection($this->workflows->consents($this->user())));
    }

    public function learnerConsents(string $learner)
    {
        return $this->success(ParentConsentResource::collection($this->workflows->consents($this->user(), $learner)));
    }

    public function consent(string $consent)
    {
        return $this->success(new ParentConsentResource($this->workflows->consent($this->user(), $consent)));
    }

    public function respondConsent(Request $request, string $consent, string $response)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->success(new ParentConsentResponseResource($this->workflows->respondConsent($this->user(), $consent, $response, $data['reason'] ?? null)));
    }

    public function appointments()
    {
        return $this->success(ParentAppointmentResource::collection($this->workflows->appointments($this->user())));
    }

    public function createAppointment(Request $request)
    {
        $data = $request->validate(['learner_id' => ['required', 'uuid'], 'category' => ['required', 'string'], 'preferred_from' => ['required', 'date', 'after:now'], 'preferred_to' => ['required', 'date', 'after:preferred_from'], 'reason' => ['required', 'string', 'max:2000']]);

        return $this->success(new ParentAppointmentResource($this->workflows->createAppointment($this->user(), $data)));
    }

    public function appointment(string $appointment)
    {
        return $this->success(new ParentAppointmentResource($this->workflows->appointment($this->user(), $appointment)));
    }

    public function appointmentAction(string $appointment, string $action)
    {
        return $this->success(new ParentAppointmentResource($this->workflows->appointmentAction($this->user(), $appointment, $action)));
    }

    public function progress(string $learner, string $section = 'summary')
    {
        return $this->success(new ParentLearnerProgressResource($this->progress->summary($this->user(), $learner, $section)));
    }

    public function syncPush(Request $request)
    {
        $data = $request->validate(['device_id' => ['required', 'uuid'], 'operations' => ['required', 'array', 'max:40'], 'operations.*.operation_uuid' => ['required', 'uuid'], 'operations.*.entity_type' => ['required', 'string'], 'operations.*.entity_id' => ['required', 'uuid'], 'operations.*.base_version' => ['required', 'integer', 'min:1'], 'operations.*.operation' => ['nullable', 'string'], 'operations.*.payload' => ['required', 'array']]);

        return $this->success($this->sync->push($this->user(), $data['device_id'], $data['operations']));
    }

    public function syncPull(Request $request)
    {
        return $this->success(new ParentSyncStatusResource($this->sync->pull($this->user(), $request->query('device_id'), $request->query('cursor'))));
    }

    public function syncStatus(Request $request)
    {
        return $this->success(new ParentSyncStatusResource($this->sync->status($this->user(), $request->query('device_id'))));
    }

    public function syncConflicts()
    {
        return $this->success(ParentSyncConflictResource::collection($this->sync->conflicts($this->user())));
    }

    public function resolveConflict(string $conflict)
    {
        $this->sync->resolve($this->user(), $conflict);

        return $this->success(['resolved' => true]);
    }

    public function upload(Request $request)
    {
        $data = $request->validate(['context_type' => ['required', 'string'], 'context_id' => ['nullable', 'uuid'], 'file' => ['required', 'file', 'max:10240']]);

        return $this->success(new ParentAttachmentResource($this->attachments->upload($this->user(), $data['context_type'], $data['context_id'] ?? null, $data['file'])));
    }

    public function attachment(string $attachment)
    {
        return $this->success(new ParentAttachmentResource($this->attachments->find($this->user(), $attachment)));
    }

    public function downloadAttachment(string $attachment)
    {
        return $this->attachments->download($this->user(), $attachment);
    }

    public function deleteAttachment(string $attachment)
    {
        $this->attachments->archive($this->user(), $attachment);

        return $this->success(['archived' => true]);
    }

    public function updatePushToken(Request $request, string $device)
    {
        $data = $request->validate(['push_token' => ['required', 'string', 'max:4096']]);

        return $this->success($this->devices->updatePushToken($this->user(), $device, $data['push_token']));
    }

    public function deletePushToken(string $device)
    {
        $this->devices->deletePushToken($this->user(), $device);

        return $this->success(['disabled' => true]);
    }

    public function pushDeliveries()
    {
        return $this->success(ParentPushDeliveryResource::collection($this->push->deliveries($this->user())));
    }
}
