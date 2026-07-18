<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\TeacherAnalyticsResource;
use App\Http\Resources\TeacherAnnouncementResource;
use App\Http\Resources\TeacherCalendarEventResource;
use App\Http\Resources\TeacherClassResource;
use App\Http\Resources\TeacherCommunicationResource;
use App\Http\Resources\TeacherDashboardResource;
use App\Http\Resources\TeacherDeviceResource;
use App\Http\Resources\TeacherLearnerResource;
use App\Http\Resources\TeacherOwnAssignmentResource;
use App\Http\Resources\TeacherPortalSafeResource;
use App\Http\Resources\TeacherPreferenceResource;
use App\Http\Resources\TeacherProfileResource;
use App\Http\Resources\TeacherTimetableResource;
use App\Models\SchemeLesson;
use App\Services\Assessment\ExamResultService;
use App\Services\Communication\CommunicationNotificationService;
use App\Services\Communication\CommunicationService;
use App\Services\TeacherPortal\TeacherDeviceService;
use App\Services\TeacherPortal\TeacherPortalAccessService;
use App\Services\TeacherPortal\TeacherPortalMobileService;
use App\Services\TeacherPortal\TeacherPortalService;
use App\Services\TeacherPortal\TeacherProfilePreferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TeacherPortalMobileController extends BaseApiController
{
    public function __construct(private TeacherPortalMobileService $mobile, private TeacherPortalService $portal, private TeacherPortalAccessService $access, private TeacherProfilePreferenceService $profileService, private TeacherDeviceService $devices, private CommunicationNotificationService $notifications, private CommunicationService $communications, private ExamResultService $results) {}

    private function user()
    {
        return auth()->user();
    }

    public function dashboard()
    {
        return $this->success(new TeacherDashboardResource($this->mobile->dashboard($this->user())));
    }

    public function profile()
    {
        return $this->success(new TeacherProfileResource($this->profileService->profile($this->user())));
    }

    public function updateProfile(Request $request)
    {
        return $this->success(new TeacherProfileResource($this->profileService->update($this->user(), $request->validate(['first_name' => 'sometimes|string|max:100', 'last_name' => 'sometimes|string|max:100', 'professional_summary' => 'sometimes|nullable|string|max:4000', 'email' => 'sometimes|email:rfc|max:255', 'phone' => 'sometimes|string|max:30']))));
    }

    public function assignments()
    {
        return $this->success(TeacherOwnAssignmentResource::collection($this->mobile->assignments($this->user())));
    }

    public function assignment(string $assignment)
    {
        return $this->success(new TeacherOwnAssignmentResource($this->access->requireAssignment($this->user(), $assignment)));
    }

    public function assignmentLearners(string $assignment)
    {
        return $this->success(TeacherLearnerResource::collection($this->mobile->learners($this->user(), $assignment)));
    }

    public function classes()
    {
        return $this->success(TeacherClassResource::collection($this->mobile->classes($this->user())));
    }

    public function classLearners(string $stream)
    {
        return $this->success(TeacherLearnerResource::collection($this->mobile->learners($this->user(), streamId: $stream)));
    }

    public function classTeacherLearners()
    {
        $assignment = $this->access->requireClassTeacher($this->user());

        return $this->assignmentLearners($assignment->id);
    }

    public function classTeacherDashboard()
    {
        $this->access->requireClassTeacher($this->user());

        return $this->dashboard();
    }

    public function timetable()
    {
        return $this->success(TeacherTimetableResource::collection($this->portal->timetable($this->user())));
    }

    public function timetableToday()
    {
        return $this->success(TeacherTimetableResource::collection($this->portal->timetable($this->user(), now()->dayOfWeekIso)));
    }

    public function schemes(Request $request)
    {
        $query = $this->mobile->schemes($this->user());
        if ($id = $request->route('scheme')) {
            return $this->success(new TeacherPortalSafeResource($query->with('lessons')->findOrFail($id)));
        }

        return $this->success(TeacherPortalSafeResource::collection($query->latest('created_at')->paginate($this->perPage($request))));
    }

    public function schemeLessons(Request $request, string $scheme, ?string $lesson = null)
    {
        $owned = $this->mobile->schemes($this->user())->findOrFail($scheme);
        $query = SchemeLesson::current()->where('scheme_id', $owned->id);

        return $this->success($lesson ? new TeacherPortalSafeResource($query->findOrFail($lesson)) : TeacherPortalSafeResource::collection($query->orderBy('lesson_number')->paginate($this->perPage($request))));
    }

    public function lessonPlans(Request $request, ?string $lessonPlan = null)
    {
        return $this->scoped($request, $this->mobile->plans($this->user())->with('assignment', 'schemeLesson'), $lessonPlan);
    }

    public function lessonNotes(Request $request, ?string $lessonNote = null)
    {
        return $this->scoped($request, $this->mobile->notes($this->user())->with('lessonPlan'), $lessonNote);
    }

    public function records(Request $request, ?string $record = null)
    {
        return $this->scoped($request, $this->mobile->records($this->user())->with('lessonPlan'), $record);
    }

    public function coverage(Request $request, ?string $coverage = null)
    {
        return $this->scoped($request, $this->mobile->coverage($this->user()), $coverage);
    }

    public function assessments()
    {
        return $this->success(TeacherPortalSafeResource::collection($this->mobile->assessments($this->user())));
    }

    public function assessmentPapers(string $exam)
    {
        return $this->success(TeacherPortalSafeResource::collection($this->mobile->papers($this->user(), $exam)));
    }

    public function assessment(string $exam)
    {
        $papers = $this->mobile->papers($this->user(), $exam);
        abort_if($papers->isEmpty(), 404);

        return $this->success([
            'exam_id' => $exam,
            'papers' => TeacherPortalSafeResource::collection($papers),
        ]);
    }

    public function paperLearners(string $exam, string $paper)
    {
        [$paperRow, $assignments] = $this->paperScope($paper, $exam);
        $learners = DB::table('learners')->where('school_id', $this->user()->school_id)
            ->where('active', true)->where('is_deleted', false)
            ->where(function ($query) use ($assignments) {
                foreach ($assignments as $assignment) {
                    $query->orWhere(fn ($item) => $item->where('grade_id', $assignment->grade_id)->where('stream_id', $assignment->stream_id));
                }
            })->select('id', 'admission_no', 'first_name', 'middle_name', 'last_name', 'grade_id', 'stream_id', 'active')
            ->orderBy('first_name')->paginate(config('teacher_portal.pagination_max', 50));

        return $this->success(TeacherLearnerResource::collection($learners));
    }

    public function marksEntry(string $paper)
    {
        [$paperRow, $assignments] = $this->paperScope($paper);
        $results = DB::table('exam_results as result')->join('learners as learner', 'learner.id', '=', 'result.learner_id')
            ->where('result.paper_id', $paperRow->id)->where('result.is_deleted', false)
            ->where('learner.school_id', $this->user()->school_id)
            ->where(function ($query) use ($assignments) {
                foreach ($assignments as $assignment) {
                    $query->orWhere(fn ($item) => $item->where('learner.grade_id', $assignment->grade_id)->where('learner.stream_id', $assignment->stream_id));
                }
            })->select('result.id', 'result.learner_id', 'result.marks', 'result.created_at')->get();

        return $this->success(new TeacherPortalSafeResource([
            'paper' => $paperRow,
            'results' => $results,
        ]));
    }

    public function enterMarks(Request $request, string $paper)
    {
        $marks = $request->validate(['marks' => ['required', 'array', 'min:1', 'max:'.config('teacher_portal.batch_limit', 100)], 'marks.*.learner_id' => 'required|uuid', 'marks.*.marks' => 'required|numeric|min:0'])['marks'];
        [, $assignments] = $this->paperScope($paper);
        $created = DB::transaction(function () use ($marks, $paper, $assignments) {
            return collect($marks)->map(function ($mark) use ($paper, $assignments) {
                $learner = $this->access->requireLearner($this->user(), $mark['learner_id']);
                abort_unless($assignments->contains(fn ($assignment) => $learner->grade_id === $assignment->grade_id && $learner->stream_id === $assignment->stream_id), 403);

                return $this->results->create($mark + ['paper_id' => $paper], $this->user()->school_id, $this->user()->id);
            });
        });

        return $this->created(TeacherPortalSafeResource::collection($created));
    }

    public function submitMarks(string $paper)
    {
        $this->paperScope($paper);

        return $this->badRequest('This assessment engine persists authorized marks immediately and has no separate teacher lock or submission state.');
    }

    public function communications(Request $request, ?string $communication = null)
    {
        $data = $this->mobile->communications($this->user(), $communication);

        return $this->success($communication ? new TeacherCommunicationResource($data) : TeacherCommunicationResource::collection($data));
    }

    public function communicationPreview(Request $request)
    {
        $data = $this->communicationData($request);

        return $this->success(new TeacherPortalSafeResource($this->communications->previewDefinition($this->user(), $data)));
    }

    public function communicationCreate(Request $request)
    {
        return $this->created(new TeacherCommunicationResource($this->communications->create($this->user(), $this->communicationData($request))));
    }

    public function communicationSubmit(string $communication)
    {
        return $this->success(new TeacherCommunicationResource($this->communications->submit($this->user(), $communication)));
    }

    public function communicationSend(string $communication)
    {
        return $this->success(new TeacherCommunicationResource($this->communications->send($this->user(), $communication)));
    }

    public function notifications()
    {
        $this->access->teacher($this->user());

        return $this->success(TeacherPortalSafeResource::collection($this->notifications->index($this->user())));
    }

    public function unreadCount()
    {
        return $this->success(['unread_count' => $this->notifications->unreadCount($this->user())]);
    }

    public function notificationState(string $notification, string $state)
    {
        return $this->success(new TeacherPortalSafeResource($this->notifications->state($this->user(), $notification, $state)));
    }

    public function announcements(?string $announcement = null)
    {
        $items = $this->notifications->portalAnnouncements($this->user());
        if ($announcement) {
            $item = $items->firstWhere('id', $announcement);
            abort_unless($item, 404);

            return $this->success(new TeacherAnnouncementResource($item));
        }

        return $this->success(TeacherAnnouncementResource::collection($items));
    }

    public function announcementRead(string $announcement)
    {
        $this->notifications->readAnnouncement($this->user(), $announcement);

        return $this->success(['read' => true]);
    }

    public function calendar(Request $request, bool $upcoming = false)
    {
        $v = $request->validate(['date_from' => 'nullable|date', 'date_to' => 'nullable|date|after_or_equal:date_from']);
        $from = $upcoming ? today()->toDateString() : ($v['date_from'] ?? today()->toDateString());
        $to = $upcoming ? today()->addDays(30)->toDateString() : ($v['date_to'] ?? today()->addDays(30)->toDateString());

        return $this->success(TeacherCalendarEventResource::collection($this->mobile->calendar($this->user(), $from, $to)));
    }

    public function preferences()
    {
        return $this->success(new TeacherPreferenceResource($this->profileService->preferences($this->user())));
    }

    public function updatePreferences(Request $request)
    {
        return $this->success(new TeacherPreferenceResource($this->profileService->updatePreferences($this->user(), $request->validate($this->preferenceRules()))));
    }

    public function devices()
    {
        return $this->success(TeacherDeviceResource::collection($this->devices->index($this->user())));
    }

    public function registerDevice(Request $request)
    {
        return $this->created(new TeacherDeviceResource($this->devices->register($this->user(), $request->validate(['device_identifier' => 'required|string|max:500', 'platform' => ['required', Rule::in(['android', 'ios', 'web'])], 'app_version' => 'nullable|string|max:50', 'device_name' => 'nullable|string|max:120', 'push_token' => 'nullable|string|max:4096']))));
    }

    public function revokeDevice(string $device)
    {
        $this->devices->revoke($this->user(), $device);

        return $this->success(null, 'Device revoked.');
    }

    public function analytics()
    {
        return $this->success(new TeacherAnalyticsResource($this->mobile->analytics($this->user())));
    }

    private function scoped(Request $request, $query, ?string $id)
    {
        return $this->success($id ? new TeacherPortalSafeResource($query->findOrFail($id)) : TeacherPortalSafeResource::collection($query->latest()->paginate($this->perPage($request))));
    }

    private function perPage(Request $request): int
    {
        return max(1, min($request->integer('per_page', 20), config('teacher_portal.pagination_max', 50)));
    }

    private function communicationData(Request $request): array
    {
        $targetTypes = str_contains($request->path(), '/class-teacher/') ? 'class_teacher_stream' : 'class_teacher_stream,subject_teacher_assignment';

        return $request->validate(['communication_type' => 'required|string|max:50', 'category' => 'required|string|max:50', 'priority' => 'sometimes|in:low,normal,high,critical', 'subject' => 'required|string|max:255', 'body' => 'required|string|max:10000', 'channels' => 'required|array|min:1', 'channels.*' => 'in:in_app,email', 'targets' => 'required|array|min:1', 'targets.*.target_type' => 'required|in:'.$targetTypes, 'targets.*.options' => 'required|array']);
    }

    private function preferenceRules(): array
    {
        $rules = array_fill_keys(['show_todays_timetable', 'show_pending_lesson_plans', 'show_curriculum_coverage', 'show_notifications', 'show_announcements', 'show_attendance_summary', 'show_assessment_summary', 'show_performance_analytics', 'email_enabled', 'sms_enabled', 'in_app_enabled'], 'sometimes|boolean');

        return $rules + ['preferred_language' => 'sometimes|string|max:10', 'timezone' => 'sometimes|timezone', 'default_assignment_id' => 'nullable|uuid', 'default_stream_id' => 'nullable|uuid', 'timetable_view' => 'sometimes|in:day,week,agenda', 'digest_frequency' => 'sometimes|in:immediate,daily,weekly,none', 'quiet_hours_start' => 'nullable|date_format:H:i', 'quiet_hours_end' => 'nullable|date_format:H:i', 'language' => 'sometimes|string|max:10'];
    }

    private function paperScope(string $paper, ?string $exam = null): array
    {
        $query = DB::table('exam_papers as paper')
            ->join('exam_learning_areas as area', 'area.id', '=', 'paper.exam_learning_area_id')
            ->join('exams as exam', 'exam.id', '=', 'area.exam_id')
            ->where('paper.id', $paper)->where('paper.is_deleted', false)
            ->where('exam.school_id', $this->user()->school_id)->where('exam.status', 'published')->where('exam.is_deleted', false);
        if ($exam) {
            $query->where('exam.id', $exam);
        }
        $paperRow = $query->select('paper.id', 'paper.paper_name', 'paper.paper_number', 'paper.max_marks', 'area.learning_area_id', 'exam.id as exam_id', 'exam.academic_year_id', 'exam.term_id')->first();
        abort_unless($paperRow, 404);
        $assignments = $this->access->assignments($this->user())->filter(fn ($assignment) => $assignment->learning_area_id === $paperRow->learning_area_id && $assignment->academic_year_id === $paperRow->academic_year_id && $assignment->term_id === $paperRow->term_id)->values();
        abort_if($assignments->isEmpty(), 403);

        return [$paperRow, $assignments];
    }
}
