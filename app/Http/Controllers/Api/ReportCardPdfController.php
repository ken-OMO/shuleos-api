<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        $id = $request->attributes->get('tenant_school_id') ?? $request->input('school_id');
        abort_if(! $id, 403, 'School context not found.');

        return (string) $id;
    }
}
