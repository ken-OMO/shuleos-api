<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\Assessment\ExamLearningAreaAnalysisService;
use Illuminate\Http\Request;

class ExamLearningAreaAnalysisController extends BaseApiController
{
    public function __construct(
        private readonly ExamLearningAreaAnalysisService $service
    ) {}

    public function __invoke(Request $request, string $exam)
    {
        $filters = $request->validate([
            'grade_id' => 'sometimes|required|uuid',
            'stream_id' => 'sometimes|required|uuid',
            'learning_area_id' => 'sometimes|required|uuid',
            'top_limit' => 'sometimes|required|integer|min:1|max:50',
        ]);

        return $this->success(
            $this->service->analyse($request->user(), $exam, $filters)
        );
    }
}
