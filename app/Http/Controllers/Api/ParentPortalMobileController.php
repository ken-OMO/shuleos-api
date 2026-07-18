<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\ParentAnnouncementResource;
use App\Http\Resources\ParentAttendanceResource;
use App\Http\Resources\ParentAttendanceSummaryResource;
use App\Http\Resources\ParentCalendarEventResource;
use App\Http\Resources\ParentChildResource;
use App\Http\Resources\ParentCommunicationResource;
use App\Http\Resources\ParentDashboardResource;
use App\Http\Resources\ParentDeviceResource;
use App\Http\Resources\ParentDocumentResource;
use App\Http\Resources\ParentFinanceSummaryResource;
use App\Http\Resources\ParentHomeworkResource;
use App\Http\Resources\ParentLearnerProfileResource;
use App\Http\Resources\ParentLearningResourceResource;
use App\Http\Resources\ParentPaymentResource;
use App\Http\Resources\ParentPortalArrayResource;
use App\Http\Resources\ParentReceiptResource;
use App\Http\Resources\ParentReportCardResource;
use App\Http\Resources\ParentResultResource;
use App\Http\Resources\ParentStatementResource;
use App\Http\Resources\ParentTimetableResource;
use App\Services\Communication\CommunicationNotificationService;
use App\Services\Finance\FinancePortalService;
use App\Services\LearningResource\LearningResourceDeliveryService;
use App\Services\ParentPortal\ParentDeviceService;
use App\Services\ParentPortal\ParentPortalAccessService;
use App\Services\ParentPortal\ParentPortalAuditService;
use App\Services\ParentPortal\ParentPortalMobileService;
use App\Services\ParentPortal\ParentProfilePreferenceService;
use App\Services\ParentPortal\ParentReportCardAccessService;
use App\Services\Pdf\ReportCardPdfService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ParentPortalMobileController extends BaseApiController
{
    public function __construct(
        private readonly ParentPortalMobileService $portal,
        private readonly ParentPortalAccessService $access,
        private readonly ParentReportCardAccessService $cards,
        private readonly ReportCardPdfService $pdf,
        private readonly FinancePortalService $finance,
        private readonly CommunicationNotificationService $notifications,
        private readonly ParentProfilePreferenceService $profile,
        private readonly ParentDeviceService $devices,
        private readonly LearningResourceDeliveryService $resourceDelivery,
        private readonly ParentPortalAuditService $audit,
    ) {}

    private function user()
    {
        return auth()->user();
    }

    public function dashboard(Request $request)
    {
        $data = $request->validate(['learner_id' => ['nullable', 'uuid']]);

        return $this->success(new ParentDashboardResource($this->portal->dashboard($this->user(), $data['learner_id'] ?? null)));
    }

    public function children()
    {
        return $this->success(ParentChildResource::collection($this->portal->children($this->user())));
    }

    public function child(string $learner)
    {
        $items = collect($this->portal->children($this->user()));
        $item = $items->firstWhere('id', $learner);
        abort_unless($item, 404);

        return $this->success(new ParentChildResource($item));
    }

    public function childProfile(string $learner)
    {
        return $this->success(new ParentLearnerProfileResource($this->portal->childProfile($this->user(), $learner)));
    }

    public function attendance(Request $request, string $learner)
    {
        $filters = $this->attendanceFilters($request);
        $page = $this->portal->attendanceQuery($this->user(), $learner, $filters)->paginate($this->perPage($request));

        return $this->success(ParentAttendanceResource::collection($page));
    }

    public function attendanceSummary(Request $request, string $learner)
    {
        return $this->success(new ParentAttendanceSummaryResource($this->portal->attendanceSummary($this->user(), $learner, $this->attendanceFilters($request))));
    }

    public function timetable(string $learner)
    {
        return $this->success(ParentTimetableResource::collection($this->portal->timetable($this->user(), $learner)));
    }

    public function timetableToday(string $learner)
    {
        return $this->success(ParentTimetableResource::collection($this->portal->timetable($this->user(), $learner, now()->dayOfWeekIso)));
    }

    public function homework(Request $request, string $learner)
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:30'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date', 'after_or_equal:due_from'],
            'learning_area_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->success(ParentHomeworkResource::collection($this->portal->homework($this->user(), $learner, $filters)));
    }

    public function homeworkShow(string $learner, string $homework)
    {
        return $this->success(new ParentHomeworkResource($this->portal->homeworkItem($this->user(), $learner, $homework)));
    }

    public function learningResources(Request $request, string $learner)
    {
        $items = $this->portal->learningResources($this->user(), $learner)->paginate($this->perPage($request));

        return $this->success(ParentLearningResourceResource::collection($items));
    }

    public function learningResource(string $learner, string $resource)
    {
        $item = $this->portal->learningResources($this->user(), $learner)->whereKey($resource)->firstOrFail();

        return $this->success(new ParentLearningResourceResource($item));
    }

    public function learningResourceDownload(string $learner, string $resource): Response
    {
        $item = $this->portal->learningResources($this->user(), $learner)->whereKey($resource)->firstOrFail();

        return $this->resourceDelivery->download($this->user(), $item, learnerId: $learner);
    }

    public function results(string $learner)
    {
        return $this->success(ParentResultResource::collection($this->portal->results($this->user(), $learner)));
    }

    public function result(string $learner, string $exam)
    {
        return $this->success(new ParentResultResource($this->portal->results($this->user(), $learner, $exam)[0]));
    }

    public function reportCards(string $learner)
    {
        return $this->success(ParentReportCardResource::collection($this->portal->reportCards($this->user(), $learner)));
    }

    public function reportCard(string $learner, string $reportCard)
    {
        $decision = $this->cards->decision($this->user(), $learner, $reportCard);
        abort_unless($decision['report_card'] ?? null, 404);

        return $this->success(new ParentReportCardResource(['report_card' => $decision['report_card'], 'access' => collect($decision)->except('report_card')->all()]));
    }

    public function reportCardDownload(string $learner, string $reportCard): Response
    {
        $user = $this->user();
        $this->cards->requireDownload($user, $learner, $reportCard);
        $document = $this->pdf->make($user->school_id, $reportCard);
        $this->audit->record($user, 'report_card_downloaded', $learner, 'report_card', $reportCard);

        return $document['pdf']->download($document['filename']);
    }

    public function finance(string $learner, string $section)
    {
        $resource = match ($section) {
            'summary' => ParentFinanceSummaryResource::class,
            'statement' => ParentStatementResource::class,
            'payments' => ParentPaymentResource::class,
            default => ParentPortalArrayResource::class,
        };
        $data = $this->portal->finance($this->user(), $learner, $section);

        return $this->success($section === 'payments' || $section === 'invoices' ? $resource::collection($data) : new $resource($data));
    }

    public function financeSummary(string $learner)
    {
        return $this->finance($learner, 'summary');
    }

    public function financeStatement(string $learner)
    {
        return $this->finance($learner, 'statement');
    }

    public function financeInvoices(string $learner)
    {
        return $this->finance($learner, 'invoices');
    }

    public function financePayments(string $learner)
    {
        return $this->finance($learner, 'payments');
    }

    public function receipt(string $learner, string $payment)
    {
        $child = $this->access->requireLinkedLearner($this->user(), $learner);

        return $this->success(new ParentReceiptResource($this->finance->receipt($this->user(), $child, $payment)));
    }

    public function communications(?string $communication = null)
    {
        $data = $this->portal->communications($this->user(), $communication);

        return $this->success($communication ? new ParentCommunicationResource($data) : ParentCommunicationResource::collection($data));
    }

    public function notificationIndex()
    {
        $this->access->parent($this->user());

        return $this->success(ParentPortalArrayResource::collection($this->notifications->index($this->user())));
    }

    public function notificationUnreadCount()
    {
        $this->access->parent($this->user());

        return $this->success(['unread_count' => $this->notifications->unreadCount($this->user())]);
    }

    public function notificationState(string $notification, string $state)
    {
        $this->access->parent($this->user());

        return $this->success(new ParentPortalArrayResource($this->notifications->state($this->user(), $notification, $state)));
    }

    public function notificationRead(string $notification)
    {
        return $this->notificationState($notification, 'read');
    }

    public function notificationUnread(string $notification)
    {
        return $this->notificationState($notification, 'unread');
    }

    public function notificationArchive(string $notification)
    {
        return $this->notificationState($notification, 'archived');
    }

    public function notificationDismiss(string $notification)
    {
        return $this->notificationState($notification, 'dismissed');
    }

    public function announcements(?string $announcement = null)
    {
        $items = $this->notifications->portalAnnouncements($this->user());
        if ($announcement) {
            $item = $items->firstWhere('id', $announcement);
            abort_unless($item, 404);

            return $this->success(new ParentAnnouncementResource($item));
        }

        return $this->success(ParentAnnouncementResource::collection($items));
    }

    public function announcementRead(string $announcement)
    {
        $this->access->parent($this->user());
        $this->notifications->readAnnouncement($this->user(), $announcement);

        return $this->success(['read' => true]);
    }

    public function calendar(Request $request, bool $upcoming = false)
    {
        $data = $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'], 'learner_id' => ['nullable', 'uuid']]);
        $from = $upcoming ? now()->toDateString() : ($data['date_from'] ?? now()->toDateString());
        $to = $upcoming ? now()->addDays(config('parent_portal.calendar_upcoming_days', 30))->toDateString() : ($data['date_to'] ?? now()->addDays(config('parent_portal.calendar_default_days', 30))->toDateString());

        return $this->success(ParentCalendarEventResource::collection($this->portal->calendar($this->user(), $from, $to, $data['learner_id'] ?? null)));
    }

    public function calendarIndex(Request $request)
    {
        return $this->calendar($request);
    }

    public function calendarUpcoming(Request $request)
    {
        return $this->calendar($request, true);
    }

    public function documents(string $learner)
    {
        return $this->success(ParentDocumentResource::collection($this->portal->documents($this->user(), $learner)));
    }

    public function documentDownload(string $learner, string $document): Response
    {
        return $this->reportCardDownload($learner, $this->portal->documentReportCardId($this->user(), $learner, $document));
    }

    public function profile()
    {
        return $this->success(new ParentPortalArrayResource($this->profile->profile($this->user())));
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate(['first_name' => ['sometimes', 'string', 'max:100'], 'last_name' => ['sometimes', 'string', 'max:100'], 'email' => ['sometimes', 'email:rfc', 'max:255'], 'phone' => ['sometimes', 'string', 'max:30']]);

        return $this->success(new ParentPortalArrayResource($this->profile->updateProfile($this->user(), $data)));
    }

    public function preferences()
    {
        return $this->success(new ParentPortalArrayResource($this->profile->preferences($this->user())));
    }

    public function updatePreferences(Request $request)
    {
        $data = $request->validate([
            'email_enabled' => ['sometimes', 'boolean'], 'sms_enabled' => ['sometimes', 'boolean'], 'in_app_enabled' => ['sometimes', 'boolean'],
            'digest_frequency' => ['sometimes', Rule::in(['immediate', 'daily', 'weekly', 'none'])],
            'quiet_hours_start' => ['nullable', 'date_format:H:i'], 'quiet_hours_end' => ['nullable', 'date_format:H:i'],
            'timezone' => ['sometimes', 'timezone'], 'language' => ['sometimes', 'string', 'max:10'], 'emergency_override' => ['sometimes', 'boolean'], 'marketing_opt_out' => ['sometimes', 'boolean'],
        ]);

        return $this->success(new ParentPortalArrayResource($this->profile->updatePreferences($this->user(), $data)));
    }

    public function devices()
    {
        return $this->success(ParentDeviceResource::collection($this->devices->index($this->user())));
    }

    public function registerDevice(Request $request)
    {
        $data = $request->validate(['device_identifier' => ['required', 'string', 'max:500'], 'platform' => ['required', Rule::in(['android', 'ios', 'web'])], 'app_version' => ['nullable', 'string', 'max:50'], 'device_name' => ['nullable', 'string', 'max:120'], 'push_token' => ['nullable', 'string', 'max:4096']]);

        return $this->created(new ParentDeviceResource($this->devices->register($this->user(), $data)));
    }

    public function revokeDevice(string $device)
    {
        $this->devices->revoke($this->user(), $device);

        return $this->success(null, 'Device revoked.');
    }

    private function attendanceFilters(Request $request): array
    {
        return $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'], 'academic_year_id' => ['nullable', 'uuid'], 'term_id' => ['nullable', 'uuid'], 'status' => ['nullable', 'string', 'max:30'], 'per_page' => ['nullable', 'integer', 'min:1']]);
    }

    private function perPage(Request $request): int
    {
        return max(1, min((int) $request->integer('per_page', 20), config('parent_portal.pagination_max', 50)));
    }
}
