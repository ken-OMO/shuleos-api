<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\LearnerAcademicProgressResource;
use App\Http\Resources\LearnerAnalyticsResource;
use App\Http\Resources\LearnerAttachmentResource;
use App\Http\Resources\LearnerCalendarEventResource;
use App\Http\Resources\LearnerCommunicationResource;
use App\Http\Resources\LearnerDashboardPhaseTwoResource;
use App\Http\Resources\LearnerDeviceResource;
use App\Http\Resources\LearnerHelpRequestResource;
use App\Http\Resources\LearnerHomeworkResource;
use App\Http\Resources\LearnerHomeworkSubmissionResource;
use App\Http\Resources\LearnerLearningResourceResource;
use App\Http\Resources\LearnerOfflineResourceResource;
use App\Http\Resources\LearnerPreferenceResource;
use App\Http\Resources\LearnerProfileResource;
use App\Http\Resources\LearnerPushDeliveryResource;
use App\Http\Resources\LearnerSubmissionHistoryResource;
use App\Http\Resources\LearnerSyncConflictResource;
use App\Http\Resources\LearnerSyncStatusResource;
use App\Http\Resources\LearnerTaskResource;
use App\Services\Attendance\AttendanceReadService;
use App\Services\Communication\CommunicationNotificationService;
use App\Services\Homework\HomeworkLearnerService;
use App\Services\LearnerPortal\LearnerAcademicProgressService;
use App\Services\LearnerPortal\LearnerDeviceService;
use App\Services\LearnerPortal\LearnerOfflineResourceService;
use App\Services\LearnerPortal\LearnerPortalAccessService;
use App\Services\LearnerPortal\LearnerPortalAnalyticsService;
use App\Services\LearnerPortal\LearnerPortalAttachmentService;
use App\Services\LearnerPortal\LearnerPortalPhaseTwoService;
use App\Services\LearnerPortal\LearnerPortalService;
use App\Services\LearnerPortal\LearnerPushService;
use App\Services\LearnerPortal\LearnerSyncService;
use App\Services\LearnerPortal\LearnerTaskService;
use App\Services\LearningResource\LearningResourceDeliveryService;
use App\Services\LearningResource\LearningResourceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LearnerPortalPhaseTwoController extends BaseApiController
{
    public function __construct(private LearnerPortalPhaseTwoService $portal, private LearnerPortalService $phaseOne, private LearnerPortalAccessService $access, private LearnerTaskService $tasks, private HomeworkLearnerService $homework, private LearnerPortalAttachmentService $attachments, private LearnerOfflineResourceService $offline, private LearningResourceDeliveryService $delivery, private LearnerSyncService $sync, private LearnerDeviceService $devices, private LearnerPushService $push, private LearnerAcademicProgressService $progress, private LearnerPortalAnalyticsService $analytics, private CommunicationNotificationService $notifications) {}

    public function dashboard()
    {
        return $this->success(new LearnerDashboardPhaseTwoResource($this->portal->dashboard(auth()->user())));
    }

    public function tasks()
    {
        return $this->success(LearnerTaskResource::collection($this->tasks->tasks(auth()->user())));
    }

    public function analytics()
    {
        return $this->success(new LearnerAnalyticsResource($this->analytics->summary(auth()->user())));
    }

    public function homeworkIndex()
    {
        return $this->success(LearnerHomeworkResource::collection($this->homework->records(auth()->user())));
    }

    public function homeworkShow(string $homework)
    {
        return $this->success(new LearnerHomeworkResource($this->homework->record(auth()->user(), $homework)->load('assignment.resources', 'submissions.files', 'submissions.mark')));
    }

    public function saveSubmission(Request $request, string $homework)
    {
        $data = $request->validate(['text_response' => ['nullable', 'string', 'max:50000', 'not_regex:/<(script|iframe|form|object|embed)\b/i'], 'external_url' => ['nullable', 'url', 'max:2048'], 'learner_comment' => ['nullable', 'string', 'max:2000'], 'base_version' => ['sometimes', 'integer', 'min:1']]);
        if (! empty($data['text_response'])) {
            $data['text_response'] = strip_tags($data['text_response']);
        }
        if (! empty($data['external_url'])) {
            $data['external_url'] = $this->homework->safeUrl($data['external_url']);
        }

        return $this->success(new LearnerHomeworkSubmissionResource($this->homework->draft(auth()->user(), $homework, $data)));
    }

    public function submissionAction(string $homework, string $action)
    {
        $submission = match ($action) {
            'submit' => $this->homework->submit(auth()->user(), $homework), 'withdraw' => $this->homework->withdraw(auth()->user(), $homework), 'resubmit' => $this->homework->resubmit(auth()->user(), $homework), default => abort(422)
        };

        return $this->success(new LearnerHomeworkSubmissionResource($submission));
    }

    public function submissionHistory(string $homework)
    {
        return $this->success(LearnerSubmissionHistoryResource::collection($this->homework->history(auth()->user(), $homework)));
    }

    public function upload(Request $request)
    {
        $data = $request->validate(['context_type' => ['required', Rule::in(['homework_submission', 'profile_image', 'help_request'])], 'context_id' => ['nullable', 'uuid'], 'file' => ['required', 'file']]);

        return $this->created(new LearnerAttachmentResource($this->attachments->upload(auth()->user(), $data['context_type'], $data['context_id'] ?? null, $data['file'])));
    }

    public function attachment(string $attachment)
    {
        return $this->success(new LearnerAttachmentResource($this->attachments->find(auth()->user(), $attachment)));
    }

    public function attachmentDownload(string $attachment)
    {
        return $this->attachments->download(auth()->user(), $attachment);
    }

    public function attachmentDelete(string $attachment)
    {
        $this->attachments->archive(auth()->user(), $attachment);

        return $this->success();
    }

    public function resources()
    {
        $learner = $this->access->learner(auth()->user());

        return $this->success(LearnerLearningResourceResource::collection(app(LearningResourceService::class)->publishedForLearner($learner->school_id, $learner->grade_id, $learner->stream_id, ['assigned_class', 'grade', 'school'])->paginate(20)));
    }

    public function resource(string $resource)
    {
        return $this->success(new LearnerLearningResourceResource($this->offline->visible(auth()->user(), $resource)));
    }

    public function resourceDownload(string $resource)
    {
        $learner = $this->access->learner(auth()->user());

        return $this->delivery->download(auth()->user(), $this->offline->visible(auth()->user(), $resource), learnerId: $learner->id);
    }

    public function offline(string $resource, bool $remove = false)
    {
        if ($remove) {
            $this->offline->remove(auth()->user(), $resource);

            return $this->success();
        }

        return $this->success(new LearnerOfflineResourceResource($this->offline->mark(auth()->user(), $resource)));
    }

    public function offlineIndex()
    {
        return $this->success(LearnerOfflineResourceResource::collection($this->offline->index(auth()->user())));
    }

    public function syncPush(Request $request)
    {
        $data = $request->validate(['device_id' => ['required', 'uuid'], 'operations' => ['required', 'array', 'max:40'], 'operations.*.operation_uuid' => ['required', 'uuid'], 'operations.*.entity_type' => ['required', 'string'], 'operations.*.entity_id' => ['required', 'uuid'], 'operations.*.operation' => ['sometimes', 'string'], 'operations.*.base_version' => ['required', 'integer', 'min:1'], 'operations.*.payload' => ['required', 'array']]);

        return $this->success($this->sync->push(auth()->user(), $data['device_id'], $data['operations']));
    }

    public function syncPull(Request $request)
    {
        $data = $request->validate(['device_id' => ['required', 'uuid'], 'cursor' => ['nullable', 'string']]);

        return $this->success($this->sync->pull(auth()->user(), $data['device_id'], $data['cursor'] ?? null));
    }

    public function syncStatus(Request $request)
    {
        $data = $request->validate(['device_id' => ['required', 'uuid']]);

        return $this->success(new LearnerSyncStatusResource($this->sync->status(auth()->user(), $data['device_id'])));
    }

    public function syncConflicts()
    {
        return $this->success(LearnerSyncConflictResource::collection($this->sync->conflicts(auth()->user())));
    }

    public function syncResolve(string $conflict)
    {
        $this->sync->resolve(auth()->user(), $conflict);

        return $this->success();
    }

    public function devices()
    {
        return $this->success(LearnerDeviceResource::collection($this->devices->index(auth()->user())));
    }

    public function registerDevice(Request $request)
    {
        $data = $request->validate(['device_identifier' => ['required', 'string', 'min:8', 'max:255'], 'platform' => ['required', Rule::in(['android', 'ios', 'web', 'pwa'])], 'device_name' => ['nullable', 'string', 'max:120'], 'app_version' => ['nullable', 'string', 'max:40']]);

        return $this->created(new LearnerDeviceResource($this->devices->register(auth()->user(), $data)));
    }

    public function revokeDevice(string $device)
    {
        $this->devices->revoke(auth()->user(), $device);

        return $this->success();
    }

    public function pushToken(Request $request, string $device, bool $remove = false)
    {
        $data = $remove ? [] : $request->validate(['push_token' => ['required', 'string', 'max:4096']]);

        return $this->success(new LearnerDeviceResource($this->devices->token(auth()->user(), $device, $remove ? null : $data['push_token'])));
    }

    public function pushDeliveries()
    {
        return $this->success(LearnerPushDeliveryResource::collection($this->push->deliveries(auth()->user())));
    }

    public function results(?string $exam = null)
    {
        return $this->success($this->phaseOne->results(auth()->user(), $exam ? ['exam_id' => $exam] : []));
    }

    public function progress(?string $learningArea = null)
    {
        return $this->success(new LearnerAcademicProgressResource($this->progress->summary(auth()->user(), $learningArea, request()->integer('periods', 6))));
    }

    public function trends()
    {
        return $this->success(new LearnerAcademicProgressResource($this->progress->trends(auth()->user(), request()->integer('periods', 6))));
    }

    public function calendar(bool $upcoming = false)
    {
        return $this->success(new LearnerCalendarEventResource($this->portal->calendar(auth()->user(), $upcoming)));
    }

    public function attendanceCalendar(Request $request)
    {
        $days = max(1, min($request->integer('days', 30), 366));
        $query = app(AttendanceReadService::class)->learner(auth()->user())->whereDate('attendance_date', '>=', today()->subDays($days - 1));

        return $this->success(['range_days' => $days, 'items' => $query->limit(366)->get()]);
    }

    public function communications(?string $communication = null)
    {
        return $this->success(new LearnerCommunicationResource($this->portal->communications(auth()->user(), $communication)));
    }

    public function createHelp(Request $request)
    {
        $data = $request->validate(['category' => ['required', Rule::in(['academic_help', 'homework_clarification', 'timetable_issue', 'technical_support', 'wellbeing_support', 'safeguarding_concern'])], 'subject' => ['required', 'string', 'max:160'], 'message' => ['required', 'string', 'max:5000']]);

        return $this->created(new LearnerHelpRequestResource($this->portal->createHelp(auth()->user(), $data)));
    }

    public function help(?string $help = null)
    {
        return $this->success(new LearnerHelpRequestResource($this->portal->help(auth()->user(), $help)));
    }

    public function notifications()
    {
        $this->access->learner(auth()->user());

        return $this->success($this->notifications->index(auth()->user()));
    }

    public function unread()
    {
        $this->access->learner(auth()->user());

        return $this->success(['count' => $this->notifications->unreadCount(auth()->user())]);
    }

    public function notificationState(string $notification, string $state)
    {
        $this->access->learner(auth()->user());

        return $this->success($this->notifications->state(auth()->user(), $notification, $state));
    }

    public function announcements(?string $announcement = null)
    {
        $this->access->learner(auth()->user());
        $items = $this->notifications->announcements(auth()->user());
        if ($announcement) {
            abort_unless(collect($items->items())->contains('id', $announcement), 404);
        }

        return $this->success($announcement ? collect($items->items())->firstWhere('id', $announcement) : $items);
    }

    public function announcementRead(string $announcement)
    {
        $this->access->learner(auth()->user());
        $this->notifications->readAnnouncement(auth()->user(), $announcement);

        return $this->success();
    }

    public function profile()
    {
        return $this->success(new LearnerProfileResource(['learner' => $this->access->learner(auth()->user()), 'preferences' => $this->portal->preferences(auth()->user())]));
    }

    public function preferences()
    {
        return $this->success(new LearnerPreferenceResource($this->portal->preferences(auth()->user())));
    }

    public function updatePreferences(Request $request)
    {
        $data = $request->validate(['display_name' => ['sometimes', 'nullable', 'string', 'max:120'], 'profile_image_attachment_id' => ['sometimes', 'nullable', 'uuid'], 'preferred_language' => ['sometimes', 'string', 'max:12'], 'timezone' => ['sometimes', 'timezone:all'], 'dashboard_widgets' => ['sometimes', 'array', 'max:30'], 'notification_preferences' => ['sometimes', 'array', 'max:20'], 'accessibility_preferences' => ['sometimes', 'array', 'max:20'], 'digest_frequency' => ['sometimes', Rule::in(['never', 'daily', 'weekly'])], 'quiet_hours_start' => ['sometimes', 'nullable', 'date_format:H:i'], 'quiet_hours_end' => ['sometimes', 'nullable', 'date_format:H:i']]);

        return $this->success(new LearnerPreferenceResource($this->portal->updatePreferences(auth()->user(), $data)));
    }
}
