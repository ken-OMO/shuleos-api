<?php

namespace App\Services\Assessment;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PaperResultValueService
{
    /**
     * Missing or null marks never imply absence.
     *
     * @return array{is_absent: bool, marks: float|null}
     */
    public function normalize(array $data, float $maximumMarks): array
    {
        Validator::make($data, [
            'is_absent' => ['sometimes', 'boolean'],
        ])->validate();

        if (! is_finite($maximumMarks) || $maximumMarks <= 0) {
            throw ValidationException::withMessages([
                'paper_id' => 'The paper must have a positive finite maximum.',
            ]);
        }

        $absent = in_array($data['is_absent'] ?? false, [true, 1, '1'], true);
        $marks = $data['marks'] ?? null;

        if ($absent) {
            if ($marks !== null) {
                throw ValidationException::withMessages([
                    'marks' => 'Absent paper results cannot contain marks.',
                ]);
            }

            return ['is_absent' => true, 'marks' => null];
        }

        if (! is_numeric($marks)
            || ! is_finite((float) $marks)
            || (float) $marks < 0
            || (float) $marks > $maximumMarks) {
            throw ValidationException::withMessages([
                'marks' => 'A scored paper requires numeric marks between zero and the paper maximum.',
            ]);
        }

        return [
            'is_absent' => false,
            'marks' => round((float) $marks, 2),
        ];
    }
}
