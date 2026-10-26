<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\ReportCardResource;
use App\Models\User;
use App\Services\Assessment\AssessmentPublicationHistoryService;
use App\Services\Assessment\AssessmentReissuePreparationService;
use App\Services\Assessment\AssessmentReissuePublicationService;
use App\Services\Assessment\AssessmentReissueRequestService;
use App\Services\Assessment\AssessmentReissueReviewService;
use App\Services\Pdf\ReportCardPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssessmentReissueController extends BaseCrudController
{
    private const REVIEW_FIELDS = [
        'id', 'learner_id', 'learning_area_id', 'paper_id', 'grade_id', 'stream_id',
        'marks', 'is_absent', 'has_absence', 'marks_obtained', 'maximum_marks',
        'percentage', 'total_score', 'overall_score', 'average_percentage',
        'total_points', 'grading_snapshot', 'overall_grade', 'ranking_method',
        'stream_position', 'grade_position', 'school_position', 'total_learners',
        'class_teacher_comment', 'principal_comment',
    ];

    public function index(Request $request)
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'exam_id' => 'sometimes|uuid',
            'status' => 'sometimes|in:pending,rejected,approved,prepared,ready,published,cancelled',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $rows = DB::table('assessment_reissue_requests')
            ->where('school_id', $actor->school_id)
            ->when(isset($data['exam_id']), fn ($q) => $q->where('exam_id', $data['exam_id']))
            ->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->orderByDesc('created_at')->orderBy('id')
            ->select([
                'id', 'exam_id', 'status', 'reason', 'requested_by',
                'decided_by', 'decided_at', 'reviewed_by', 'reviewed_at',
                'published_by', 'published_at', 'created_at', 'updated_at',
            ])->paginate($data['per_page'] ?? 20);

        return $this->success($rows, 'Assessment correction requests retrieved.');
    }

    public function show(Request $request, string $reissue)
    {
        $actor = $this->actor($request);
        $row = DB::table('assessment_reissue_requests')
            ->where('school_id', $actor->school_id)->where('id', $reissue)->first();
        abort_unless($row, 404);

        $data = (array) $row;
        unset($data['school_id']);
        $data['proposed_changes'] = json_decode(
            $row->proposed_changes, true, 512, JSON_THROW_ON_ERROR
        );
        $data['events'] = DB::table('assessment_reissue_events')
            ->where('school_id', $actor->school_id)->where('request_id', $reissue)
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'actor_id', 'action', 'reason', 'created_at']);

        return $this->success($data, 'Assessment correction request retrieved.');
    }

    public function store(Request $request)
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'school_id' => 'exclude',
            'exam_id' => 'required|uuid',
            'reason' => 'required|string|max:5000',
            'changes' => 'required|array|min:1|max:100',
        ]);

        $id = app(AssessmentReissueRequestService::class)->submit(
            $actor, $data['exam_id'], $data['reason'], $data['changes']
        );

        return $this->success(['id' => $id], 'Assessment correction requested.');
    }

    public function decide(Request $request, string $reissue)
    {
        $data = $request->validate([
            'school_id' => 'exclude',
            'decision' => 'required|in:approved,rejected',
            'reason' => 'required|string|max:5000',
        ]);
        app(AssessmentReissueRequestService::class)->decide(
            $this->actor($request), $reissue, $data['decision'], $data['reason']
        );

        return $this->success(['id' => $reissue], 'Correction decision recorded.');
    }

    public function prepare(Request $request, string $reissue)
    {
        $data = $request->validate([
            'school_id' => 'exclude',
            'reason' => 'required|string|max:5000',
        ]);
        $result = app(AssessmentReissuePreparationService::class)->prepare(
            $this->actor($request), $reissue, $data['reason']
        );

        return $this->success($result, 'Assessment revisions prepared for review.');
    }

    public function drafts(Request $request, string $reissue)
    {
        $actor = $this->actor($request);
        abort_unless(DB::table('assessment_reissue_requests')
            ->where('school_id', $actor->school_id)->where('id', $reissue)->exists(), 404);

        $data = $request->validate(['per_page' => 'sometimes|integer|min:1|max:100']);
        $rows = DB::table('assessment_reissue_drafts')
            ->where('school_id', $actor->school_id)->where('request_id', $reissue)
            ->orderBy('output_type')->orderBy('output_id')
            ->paginate($data['per_page'] ?? 20);

        $rows->setCollection($rows->getCollection()->map(function ($row): array {
            $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);

            return [
                'id' => $row->id,
                'output_type' => $row->output_type,
                'output_id' => $row->output_id,
                'learner_id' => $row->learner_id,
                'revision_number' => $row->revision_number,
                'candidate' => collect($payload['output'])->only(self::REVIEW_FIELDS)->all(),
                'learning_areas' => collect($payload['learning_areas'] ?? [])
                    ->filter(fn ($detail) => ! $detail['is_deleted'])
                    ->map(fn ($detail) => collect($detail)->only([
                        'id', 'learning_area_id', 'is_absent', 'marks_obtained',
                        'maximum_marks', 'percentage', 'grade_code', 'points',
                        'teacher_comment',
                    ])->all())->values()->all(),
            ];
        }));

        return $this->success($rows, 'Prepared assessment revisions retrieved.');
    }

    public function review(Request $request, string $reissue)
    {
        $data = $this->publicationAction($request);
        app(AssessmentReissueReviewService::class)->approve(
            $this->actor($request), $reissue, $data['prepared_sha256'], $data['reason']
        );

        return $this->success(['id' => $reissue], 'Prepared revisions approved.');
    }

    public function publish(Request $request, string $reissue)
    {
        $data = $this->publicationAction($request);
        $result = app(AssessmentReissuePublicationService::class)->publish(
            $this->actor($request), $reissue, $data['prepared_sha256'], $data['reason']
        );

        return $this->success($result, 'Approved assessment revisions published.');
    }

    public function versions(Request $request)
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'exam_id' => 'required|uuid',
            'output_id' => 'sometimes|uuid',
            'output_type' => 'sometimes|in:report_card,merit_list',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $rows = DB::table('assessment_publication_versions')
            ->where('school_id', $actor->school_id)->where('exam_id', $data['exam_id'])
            ->when(isset($data['output_id']), fn ($q) => $q->where('output_id', $data['output_id']))
            ->when(isset($data['output_type']), fn ($q) => $q->where('output_type', $data['output_type']))
            ->orderBy('output_type')->orderBy('output_id')->orderBy('revision_number')
            ->select([
                'id', 'exam_id', 'learner_id', 'output_type', 'output_id',
                'revision_number', 'reissue_request_id', 'published_by',
                'published_at', 'recorded_at',
            ])->paginate($data['per_page'] ?? 20);

        return $this->success($rows, 'Assessment publication versions retrieved.');
    }

    public function meritVersion(Request $request, string $version)
    {
        $record = app(AssessmentPublicationHistoryService::class)
            ->meritList($this->actor($request), $version);

        return $this->success($record, 'Historical merit values retrieved.');
    }

    public function reportVersion(Request $request, string $version)
    {
        $record = app(AssessmentPublicationHistoryService::class)
            ->reportCard($this->actor($request), $version);

        return $this->success([
            'version_id' => $record['version_id'],
            'revision_number' => $record['revision_number'],
            'report_card' => new ReportCardResource($record['card']),
        ], 'Historical report card retrieved.');
    }

    public function reportVersionPdf(Request $request, string $version)
    {
        $document = app(ReportCardPdfService::class)
            ->makeVersion($this->actor($request), $version);

        return $document['pdf']->stream($document['filename']);
    }

    public function cancel(Request $request, string $reissue)
    {
        $data = $request->validate([
            'school_id' => 'exclude',
            'reason' => 'required|string|max:5000',
        ]);

        app(AssessmentReissueRequestService::class)->cancel(
            $this->actor($request), $reissue, $data['reason']
        );

        return $this->success(['id' => $reissue], 'Assessment correction request cancelled.');
    }

    private function publicationAction(Request $request): array
    {
        return $request->validate([
            'school_id' => 'exclude',
            'reason' => 'required|string|max:5000',
            'prepared_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->school_id, 403);
        if ($request->attributes->get('client_supplied_school_id', false)) {
            throw ValidationException::withMessages([
                'school_id' => 'School ownership is determined by your authenticated account.',
            ]);
        }

        $tenant = $request->attributes->get('tenant_school_id');
        abort_if($tenant !== null && (string) $tenant !== (string) $actor->school_id, 403);

        return $actor;
    }
}
