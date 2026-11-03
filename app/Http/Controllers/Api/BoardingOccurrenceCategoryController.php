<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Boarding\BoardingOccurrenceCategoryRequest;
use App\Services\Boarding\BoardingOccurrenceCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoardingOccurrenceCategoryController extends Controller
{
    public function __construct(private BoardingOccurrenceCategoryService $categories) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,100000'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        return response()->json($this->categories->read(
            $request->user(), (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 50)
        ));
    }

    public function store(BoardingOccurrenceCategoryRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->categories->create($request->user(), $this->values($request)),
        ], 201);
    }

    public function update(
        BoardingOccurrenceCategoryRequest $request,
        string $category
    ): JsonResponse {
        return response()->json([
            'data' => $this->categories->update(
                $request->user(), $category,
                $request->validated('expected_version'), $this->values($request)
            ),
        ]);
    }

    private function values(BoardingOccurrenceCategoryRequest $request): array
    {
        return [
            'category_name' => $request->validated('category_name'),
            'active' => $request->validated('active'),
        ];
    }
}
