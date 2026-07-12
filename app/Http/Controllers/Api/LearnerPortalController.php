<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\LearnerPortalProfileResource;
use App\Services\LearnerPortal\LearnerPortalAccessService;
use App\Services\LearnerPortal\LearnerPortalService;
use App\Services\Pdf\ReportCardPdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LearnerPortalController extends BaseApiController
{
    public function __construct(private readonly LearnerPortalService $s, private readonly LearnerPortalAccessService $a, private readonly ReportCardPdfService $pdf) {}

    private function u()
    {
        return auth()->user();
    }

    public function me()
    {
        return $this->success(new LearnerPortalProfileResource($this->s->profile($this->u())));
    }

    public function dashboard()
    {
        return $this->success($this->s->dashboard($this->u()));
    }

    public function preferences()
    {
        return $this->success($this->s->preferences($this->u()));
    }

    public function updatePreferences(Request $r)
    {
        $rules = [];
        foreach (['timetable', 'attendance', 'results', 'report_cards', 'fees', 'announcements', 'notifications', 'upcoming_exams', 'learning_resources'] as $f) {
            $rules['show_'.$f] = 'sometimes|boolean';
        }

        return $this->success($this->s->updatePreferences($this->u(), $r->validate($rules)));
    }

    public function timetable(Request $r)
    {
        $v = $r->validate(['day' => 'sometimes|integer|min:1|max:7']);

        return $this->success($this->s->timetable($this->u(), $v['day'] ?? null));
    }

    public function attendance()
    {
        return $this->success($this->s->attendance($this->u()));
    }

    public function results(Request $r)
    {
        return $this->success($this->s->results($this->u(), $r->validate(['exam_id' => 'sometimes|uuid'])));
    }

    public function reportCards()
    {
        return $this->success($this->s->reportCards($this->u()));
    }

    public function reportCard(string $reportCard)
    {
        return $this->success($this->s->reportCard($this->u(), $reportCard));
    }

    public function pdf(string $reportCard): Response
    {
        $u = $this->u();
        $this->a->requireReportCard($u, $reportCard);
        $d = $this->pdf->make($u->school_id, $reportCard);

        return $d['pdf']->stream($d['filename']);
    }

    public function fees()
    {
        return $this->success($this->s->fees($this->u()));
    }

    public function exams()
    {
        return $this->success($this->s->exams($this->u()));
    }

    public function announcements()
    {
        return $this->success($this->s->announcements($this->u()));
    }

    public function notifications()
    {
        return $this->success($this->s->notifications($this->u()));
    }
}
