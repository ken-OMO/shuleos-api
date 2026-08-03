<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\ParentProfileResource;
use App\Services\ParentPortal\ParentPortalService;
use App\Services\ParentPortal\ParentReportCardAccessService;
use App\Services\Pdf\ReportCardPdfService;
use Symfony\Component\HttpFoundation\Response;

class ParentPortalController extends BaseApiController
{
    public function __construct(private readonly ParentPortalService $portal, private readonly ParentReportCardAccessService $cards, private readonly ReportCardPdfService $pdf) {}

    private function user()
    {
        return auth()->user();
    }

    public function me()
    {
        return $this->success(new ParentProfileResource($this->portal->profile($this->user())));
    }

    public function learners()
    {
        return $this->success($this->portal->learners($this->user()));
    }

    public function dashboard(string $learner)
    {
        return $this->success($this->portal->dashboard($this->user(), $learner));
    }

    public function timetable(string $learner)
    {
        return $this->success($this->portal->timetable($this->user(), $learner));
    }

    public function timetableToday(string $learner)
    {
        return $this->success($this->portal->timetable($this->user(), $learner, now()->dayOfWeekIso));
    }

    public function reportCards(string $learner)
    {
        return $this->success($this->portal->reportCards($this->user(), $learner));
    }

    public function reportCard(string $learner, string $reportCard)
    {
        return $this->success($this->portal->reportCard($this->user(), $learner, $reportCard));
    }

    public function reportCardPdf(string $learner, string $reportCard): Response
    {
        $u = $this->user();
        $this->cards->requireDownload($u, $learner, $reportCard);
        $d = $this->pdf->make($u->school_id, $reportCard);

        return $d['pdf']->stream($d['filename']);
    }

    public function attendance(string $learner)
    {
        return $this->success($this->portal->attendance($this->user(), $learner));
    }

    public function fees(string $learner)
    {
        return $this->success($this->portal->fees($this->user(), $learner));
    }

    public function announcements()
    {
        return $this->success($this->portal->announcements($this->user()));
    }

    public function notifications()
    {
        return $this->success($this->portal->notifications($this->user()));
    }
}
