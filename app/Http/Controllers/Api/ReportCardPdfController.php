<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Assessment\AssessmentTenantContext;
use App\Services\Pdf\ReportCardPdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportCardPdfController extends Controller
{
    public function __construct(private readonly ReportCardPdfService $service) {}

    public function stream(Request $request, string $id): Response
    {
        $document = $this->service->make($this->school($request), $id);

        return $document['pdf']->stream($document['filename']);
    }

    public function download(Request $request, string $id): Response
    {
        $document = $this->service->make($this->school($request), $id);

        return $document['pdf']->download($document['filename']);
    }

    private function school(Request $request): string
    {
        return AssessmentTenantContext::schoolId($request);
    }
}
