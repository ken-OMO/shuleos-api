<?php

namespace Tests\Feature;

use App\Services\Assessment\PaperResultValueService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaperResultValueTest extends TestCase
{
    public function test_zero_is_a_scored_result(): void
    {
        foreach ([0, 0.0, '0', '0.00'] as $marks) {
            $this->assertSame(
                ['is_absent' => false, 'marks' => 0.0],
                app(PaperResultValueService::class)->normalize(['marks' => $marks], 50)
            );
        }
    }

    public function test_absence_requires_explicit_true_and_has_no_marks(): void
    {
        foreach ([true, 1, '1'] as $flag) {
            foreach ([['is_absent' => $flag], ['is_absent' => $flag, 'marks' => null]] as $data) {
                $this->assertSame(
                    ['is_absent' => true, 'marks' => null],
                    app(PaperResultValueService::class)->normalize($data, 50)
                );
            }
        }
    }

    public function test_absence_rejects_marks_including_zero(): void
    {
        foreach ([0, '0', 25, '', false] as $marks) {
            $this->assertRejected(['is_absent' => true, 'marks' => $marks], 50, 'marks');
        }
    }

    public function test_missing_and_invalid_marks_do_not_become_zero_or_absence(): void
    {
        $this->assertRejected([], 50, 'marks');

        foreach ([null, '', 'ABS', false, [], -0.01, 50.01, INF, NAN] as $marks) {
            $this->assertRejected(['marks' => $marks], 50, 'marks');
        }
    }

    public function test_false_flags_require_a_numeric_score(): void
    {
        foreach ([false, 0, '0'] as $flag) {
            $this->assertSame(
                ['is_absent' => false, 'marks' => 50.0],
                app(PaperResultValueService::class)->normalize([
                    'is_absent' => $flag,
                    'marks' => 50,
                ], 50)
            );

            $this->assertRejected(['is_absent' => $flag], 50, 'marks');
        }
    }

    public function test_invalid_absence_flags_are_rejected(): void
    {
        foreach ([null, 'true', 'false', 'yes', 2, []] as $flag) {
            $this->assertRejected(['is_absent' => $flag, 'marks' => 10], 50, 'is_absent');
        }
    }

    public function test_invalid_paper_maximum_is_rejected_even_for_absence(): void
    {
        foreach ([0, -1, INF, NAN] as $maximum) {
            $this->assertRejected(['is_absent' => true], $maximum, 'paper_id');
        }
    }

    public function test_valid_scores_are_rounded_without_accepting_out_of_range_input(): void
    {
        $this->assertSame(
            ['is_absent' => false, 'marks' => 12.35],
            app(PaperResultValueService::class)->normalize(['marks' => '12.345'], 50)
        );

        $this->assertRejected(['marks' => 50.001], 50, 'marks');
        $this->assertRejected(['marks' => -0.001], 50, 'marks');
    }

    private function assertRejected(array $data, float $maximum, string $field): void
    {
        try {
            app(PaperResultValueService::class)->normalize($data, $maximum);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());

            return;
        }

        $this->fail("Expected validation failure for {$field}.");
    }
}
