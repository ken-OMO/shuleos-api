<?php

namespace Tests\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final class ExamBenchmarkMeter
{
    private bool $measuring = false;

    private int $queries = 0;

    private float $databaseMilliseconds = 0;

    private array $patterns = [];

    private int $untrackedPatterns = 0;

    public function __construct()
    {
        DB::listen(function (QueryExecuted $event): void {
            if (! $this->measuring) {
                return;
            }

            $this->queries++;
            $this->databaseMilliseconds += $event->time;

            // Bound instrumentation memory independently of learner count.
            // Bindings are deliberately excluded.
            $sql = preg_replace('/\s+/', ' ', trim($event->sql));
            $key = hash('sha256', $sql);

            if (! isset($this->patterns[$key])) {
                if (count($this->patterns) >= 100) {
                    $this->untrackedPatterns++;

                    return;
                }

                $this->patterns[$key] = [
                    'sql' => strlen($sql) > 600 ? substr($sql, 0, 600).'...' : $sql,
                    'count' => 0,
                    'database_ms' => 0,
                ];
            }

            $this->patterns[$key]['count']++;
            $this->patterns[$key]['database_ms'] += $event->time;
        });
    }

    public function measure(string $operation, callable $callback): array
    {
        if ($this->measuring) {
            throw new LogicException('Benchmark measurements cannot overlap.');
        }

        DB::connection()->disableQueryLog();
        DB::connection()->flushQueryLog();
        gc_collect_cycles();
        gc_mem_caches();

        $this->queries = 0;
        $this->databaseMilliseconds = 0;
        $this->patterns = [];
        $this->untrackedPatterns = 0;

        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $usedBefore = memory_get_usage(false);
        $memoryBefore = memory_get_usage(true);
        $started = hrtime(true);
        $this->measuring = true;
        $status = 'completed';
        $errorType = null;
        $summary = null;

        try {
            // Callbacks return a small summary, not the generated collection.
            $summary = $callback();
        } catch (Throwable $exception) {
            $status = 'failed';
            $errorType = get_class($exception);

            if ($exception instanceof QueryException) {
                // Record diagnostics without connection settings or bindings.
                $summary = [
                    'failure' => [
                        'sqlstate' => $exception->errorInfo[0] ?? (string) $exception->getCode(),
                        'driver_code' => $exception->errorInfo[1] ?? null,
                        'statement_template' => substr($exception->getSql(), 0, 2000),
                    ],
                ];
            }
        } finally {
            $this->measuring = false;
        }

        $elapsed = (hrtime(true) - $started) / 1000000;
        $peak = memory_get_peak_usage(true);
        $memoryAfter = memory_get_usage(true);
        $usedAfter = memory_get_usage(false);
        $usedPeak = memory_get_peak_usage(false);

        $patterns = array_values($this->patterns);
        usort($patterns, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return [
            'operation' => $operation,
            'status' => $status,
            'error_type' => $errorType,
            'elapsed_ms' => round($elapsed, 2),
            'query_count' => $this->queries,
            'database_ms' => round($this->databaseMilliseconds, 2),
            'used_before_mb' => round($usedBefore / 1048576, 2),
            'used_after_mb' => round($usedAfter / 1048576, 2),
            'used_peak_mb' => round($usedPeak / 1048576, 2),
            'memory_before_mb' => round($memoryBefore / 1048576, 2),
            'memory_after_mb' => round($memoryAfter / 1048576, 2),
            'peak_memory_mb' => round($peak / 1048576, 2),
            'peak_growth_mb' => round(max(0, $peak - $memoryBefore) / 1048576, 2),
            'summary' => $summary,
            'frequent_queries' => array_slice($patterns, 0, 15),
            'untracked_pattern_queries' => $this->untrackedPatterns,
        ];
    }
}
