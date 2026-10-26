<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssessmentPublicationArchiveService
{
    public function __construct(
        private readonly ExamResultLifecycleService $lifecycle
    ) {}

    /**
     * Preserve originals before the first reissue changes working outputs.
     * This method never changes report cards, merit entries or their details.
     */
    public function preserveOriginals(string $schoolId, string $examId): array
    {
        return DB::transaction(function () use ($schoolId, $examId): array {
            $this->lifecycle->lockExamForWrite($schoolId, $examId);

            $counts = ['report_card' => 0, 'merit_list' => 0];

            foreach ([
                'merit_list' => 'merit_lists',
                'report_card' => 'report_cards',
            ] as $type => $table) {
                // Include deleted published rows: deletion must not erase history.
                $rows = DB::table($table)
                    ->where('school_id', $schoolId)
                    ->where('exam_id', $examId)
                    ->where('status', 'published')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($rows as $row) {
                    $existing = DB::table('assessment_publication_versions')
                        ->where('school_id', $schoolId)
                        ->where('output_type', $type)
                        ->where('output_id', $row->id)
                        ->orderBy('revision_number')
                        ->lockForUpdate()
                        ->get();

                    if ($existing->isNotEmpty()) {
                        $original = $existing->firstWhere('revision_number', 1);

                        if (! $original) {
                            throw ValidationException::withMessages([
                                'publication_history' => 'The original publication version is missing.',
                            ]);
                        }

                    }

                    if ($row->published_at === null) {
                        throw ValidationException::withMessages([
                            'publication_history' => 'A published output has no publication timestamp.',
                        ]);
                    }

                    $payload = [
                        'schema_version' => 1,
                        'output' => (array) $row,
                    ];

                    if ($type === 'report_card') {
                        // Preserve all stored details, including deleted historical rows.
                        $payload['learning_areas'] = DB::table('report_card_learning_areas')
                            ->where('report_card_id', $row->id)
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get()
                            ->map(fn ($detail): array => (array) $detail)
                            ->all();
                    }

                    $hash = $this->fingerprint($payload);

                    if ($existing->isNotEmpty()) {
                        foreach ($existing->values() as $index => $version) {
                            $stored = json_decode(
                                $version->payload, true, 512, JSON_THROW_ON_ERROR
                            );
                            $output = $stored['output'] ?? [];

                            if ((int) $version->revision_number !== $index + 1
                                || $version->exam_id !== $examId
                                || $version->learner_id !== $row->learner_id
                                || ($stored['schema_version'] ?? null) !== 1
                                || ($output['id'] ?? null) !== $row->id
                                || ($output['school_id'] ?? null) !== $schoolId
                                || ($output['exam_id'] ?? null) !== $examId
                                || ($output['learner_id'] ?? null) !== $row->learner_id
                                || ($output['status'] ?? null) !== 'published'
                                || ! hash_equals(
                                    $version->payload_sha256, $this->fingerprint($stored)
                                )) {
                                throw ValidationException::withMessages([
                                    'publication_history' => 'The publication history failed verification.',
                                ]);
                            }
                        }

                        if (! hash_equals($existing->last()->payload_sha256, $hash)) {
                            throw ValidationException::withMessages([
                                'publication_history' => 'The published output differs from its latest archived revision.',
                            ]);
                        }

                        continue;
                    }

                    DB::table('assessment_publication_versions')->insert([
                        'id' => (string) Str::uuid(),
                        'school_id' => $schoolId,
                        'exam_id' => $examId,
                        'learner_id' => $row->learner_id,
                        'output_type' => $type,
                        'output_id' => $row->id,
                        'revision_number' => 1,
                        'reissue_request_id' => null,
                        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                        'payload_sha256' => $hash,
                        'published_at' => $row->published_at,
                        'published_by' => $row->published_by ?? null,
                        'recorded_at' => now(),
                    ]);

                    $counts[$type]++;
                }
            }

            return $counts;
        });
    }

    public function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
