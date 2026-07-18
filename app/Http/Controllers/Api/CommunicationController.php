<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\AnnouncementResource;
use App\Http\Resources\CommunicationAnalyticsResource;
use App\Http\Resources\CommunicationAuditResource;
use App\Http\Resources\CommunicationDeliveryResource;
use App\Http\Resources\CommunicationPolicyResource;
use App\Http\Resources\CommunicationPreviewResource;
use App\Http\Resources\CommunicationResource;
use App\Http\Resources\CommunicationTemplateResource;
use App\Http\Resources\NotificationResource;
use App\Services\Communication\CommunicationAnalyticsService;
use App\Services\Communication\CommunicationNotificationService;
use App\Services\Communication\CommunicationPolicyService;
use App\Services\Communication\CommunicationRecipientResolverService;
use App\Services\Communication\CommunicationService;
use App\Services\Communication\CommunicationTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunicationController extends BaseApiController
{
    public function __construct(private CommunicationService $communications, private CommunicationRecipientResolverService $resolver, private CommunicationTemplateService $templates, private CommunicationPolicyService $policies, private CommunicationNotificationService $notifications, private CommunicationAnalyticsService $analytics) {}

    public function index()
    {
        $user = auth()->user();
        $query = DB::table('communications')->where('school_id', $user->school_id);
        if (! $this->resolver->hasPermission($user, 'view_communication_analytics')) {
            $query->where('sender_user_id', $user->id);
        }

        return $this->success(CommunicationResource::collection($query->latest('created_at')->paginate(30)));
    }

    public function show(string $communication)
    {
        $row = $this->communications->find(auth()->user(), $communication);
        abort_unless($row->sender_user_id === auth()->id() || $this->resolver->hasPermission(auth()->user(), 'view_communication_analytics'), 403);

        return $this->success(new CommunicationResource($row));
    }

    public function store(Request $request)
    {
        return $this->created(new CommunicationResource($this->communications->create(auth()->user(), $request->validate($this->rules()))));
    }

    public function update(Request $request, string $communication)
    {
        return $this->success(new CommunicationResource($this->communications->update(auth()->user(), $communication, $request->validate($this->rules(true)))));
    }

    public function previewDefinition(Request $request)
    {
        return $this->success(new CommunicationPreviewResource($this->communications->previewDefinition(auth()->user(), $request->validate($this->rules()))));
    }

    public function preview(string $communication)
    {
        return $this->success(new CommunicationPreviewResource($this->communications->preview(auth()->user(), $communication)));
    }

    public function action(Request $request, string $communication, string $action)
    {
        $row = match ($action) {
            'submit' => $this->communications->submit(auth()->user(), $communication),
            'approve' => $this->communications->approve(auth()->user(), $communication),
            'reject' => $this->communications->reject(auth()->user(), $communication, $request->validate(['reason' => 'required|string|max:2000'])['reason']),
            'send', 'publish' => $this->communications->send(auth()->user(), $communication),
            'schedule' => $this->communications->schedule(auth()->user(), $communication, $request->validate(['scheduled_for' => 'required|date'])['scheduled_for']),
            'cancel' => $this->communications->cancel(auth()->user(), $communication, $request->validate(['reason' => 'required|string|max:2000'])['reason']),
            'archive' => $this->communications->archive(auth()->user(), $communication),
        };

        return $this->success(new CommunicationResource($row));
    }

    public function deliveries(string $communication)
    {
        $row = $this->communications->find(auth()->user(), $communication);
        abort_unless($row->sender_user_id === auth()->id() || $this->resolver->hasPermission(auth()->user(), 'view_communication_analytics'), 403);

        return $this->success(CommunicationDeliveryResource::collection(DB::table('communication_deliveries')->where('school_id', auth()->user()->school_id)->where('communication_id', $communication)->get()));
    }

    public function audit(string $communication)
    {
        $row = $this->communications->find(auth()->user(), $communication);
        abort_unless($row->sender_user_id === auth()->id() || $this->resolver->hasPermission(auth()->user(), 'view_communication_analytics'), 403);

        return $this->success(CommunicationAuditResource::collection(DB::table('communication_audit_logs')->where('school_id', auth()->user()->school_id)->where('communication_id', $communication)->orderBy('created_at')->get()));
    }

    public function templates()
    {
        return $this->success(CommunicationTemplateResource::collection(DB::table('communication_templates')->where(fn ($query) => $query->where('school_id', auth()->user()->school_id)->orWhereNull('school_id'))->where('active', true)->orderBy('category')->orderBy('name')->paginate(30)));
    }

    public function template(string $template)
    {
        return $this->success(new CommunicationTemplateResource($this->templates->find(auth()->user(), $template)));
    }

    public function saveTemplate(Request $request, ?string $template = null)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'category' => 'required|string|max:60', 'subject' => 'required|string|max:255', 'body' => 'required|string|max:20000']);

        return $this->success(new CommunicationTemplateResource($this->templates->save(auth()->user(), $data, $template)));
    }

    public function previewTemplate(Request $request, string $template)
    {
        return $this->success($this->templates->render(auth()->user(), $template, $request->validate(['values' => 'sometimes|array'])['values'] ?? []));
    }

    public function archiveTemplate(string $template)
    {
        return $this->success(new CommunicationTemplateResource($this->templates->archive(auth()->user(), $template)));
    }

    public function policies()
    {
        $categories = ['homework', 'attendance', 'finance', 'timetable', 'results', 'behaviour', 'announcements', 'emergency', 'general'];

        return $this->success(CommunicationPolicyResource::collection(collect($categories)->map(fn ($category) => $this->policies->policy(auth()->user(), $category))));
    }

    public function updatePolicy(Request $request, string $category)
    {
        $data = $request->validate(['enabled_channels' => 'sometimes|array|min:1', 'enabled_channels.*' => 'in:in_app,email,sms', 'sms_enabled' => 'sometimes|boolean', 'minimum_priority' => 'sometimes|in:low,normal,high,critical', 'requires_approval' => 'sometimes|boolean', 'approval_recipient_threshold' => 'sometimes|integer|min:1|max:5000', 'critical_recipient_threshold' => 'sometimes|integer|min:1|max:5000', 'allowed_sender_roles' => 'nullable|array', 'allowed_sender_roles.*' => 'uuid', 'allow_scheduling' => 'sometimes|boolean', 'default_expiry_days' => 'nullable|integer|min:1|max:365']);

        return $this->success(new CommunicationPolicyResource($this->policies->update(auth()->user(), $category, $data)));
    }

    public function notifications()
    {
        return $this->success(NotificationResource::collection($this->notifications->index(auth()->user())));
    }

    public function unreadCount()
    {
        return $this->success(['unread_count' => $this->notifications->unreadCount(auth()->user())]);
    }

    public function notificationState(string $notification, string $state)
    {
        return $this->success(new NotificationResource($this->notifications->state(auth()->user(), $notification, $state)));
    }

    public function announcements()
    {
        return $this->success(AnnouncementResource::collection($this->notifications->announcements(auth()->user())));
    }

    public function manageAnnouncements()
    {
        $user = auth()->user();
        $query = DB::table('communications')->where('school_id', $user->school_id)->where('communication_type', 'announcement');
        if (! $this->resolver->hasPermission($user, 'view_communication_analytics')) {
            $query->where('sender_user_id', $user->id);
        }

        return $this->success(AnnouncementResource::collection($query->latest('created_at')->paginate(30)));
    }

    public function storeAnnouncement(Request $request)
    {
        $request->merge(['communication_type' => 'announcement', 'category' => 'announcements']);

        return $this->store($request);
    }

    public function updateAnnouncement(Request $request, string $announcement)
    {
        $request->merge(['communication_type' => 'announcement', 'category' => 'announcements']);

        return $this->update($request, $announcement);
    }

    public function announcementRead(string $announcement)
    {
        $this->notifications->readAnnouncement(auth()->user(), $announcement);

        return $this->success(null, 'Announcement marked as read.');
    }

    public function analytics()
    {
        return $this->success(new CommunicationAnalyticsResource($this->analytics->summary(auth()->user())));
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return ['communication_type' => $required.'|in:announcement,circular,reminder,finance_notice,attendance_alert,homework_notice,timetable_notice,result_notice,behaviour_notice,emergency,general', 'category' => $required.'|string|max:50', 'priority' => 'sometimes|in:low,normal,high,critical', 'subject' => $required.'|string|max:255', 'body' => $required.'|string|max:20000', 'channels' => $required.'|array|min:1', 'channels.*' => 'in:in_app,email,sms', 'targets' => $required.'|array|min:1', 'targets.*.target_type' => 'required_with:targets|string|max:60', 'targets.*.options' => 'sometimes|array', 'expires_at' => 'nullable|date', 'metadata' => 'sometimes|array'];
    }
}
