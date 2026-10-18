<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class ExamConcurrencyRunner
{
    private array $workers = [];

    public function run(array $first, array $second): array
    {
        $connection = DB::connection()->getConfig();
        $database = $connection['database'] ?? '';

        if (! app()->environment('testing')
            || ($connection['driver'] ?? '') !== 'pgsql'
            || ! is_string($database)
            || ! preg_match('/(?:^|_)test(?:ing)?(?:_|$)/i', $database)
            || ! empty($connection['url'])
            || DB::transactionLevel() !== 0) {
            throw new RuntimeException(
                'Concurrency tests require committed fixtures in a named PostgreSQL testing database.'
            );
        }

        try {
            $a = $this->start($first + [
                'connection' => $connection,
                'hold_lock' => true,
            ]);
            $readyA = $this->awaitEvent($a, 'ready');
            $this->awaitEvent($a, 'locked');

            $b = $this->start($second + [
                'connection' => $connection,
                'hold_lock' => false,
            ]);
            $readyB = $this->awaitEvent($b, 'ready');

            if ($readyA['pid'] === $readyB['pid']) {
                throw new RuntimeException('Workers must use separate database sessions.');
            }

            $deadline = microtime(true) + 7;
            $blocked = false;

            do {
                $row = DB::selectOne(
                    'select ?::integer = ANY(pg_blocking_pids(?::integer)) as blocked',
                    [$readyA['pid'], $readyB['pid']]
                );
                $blocked = in_array($row->blocked, [true, 1, '1', 't'], true);

                if ($blocked) {
                    break;
                }

                if (! $this->workers[$b]['process']->isRunning()) {
                    throw new RuntimeException(
                        'The competing operation finished before lock contention was established.'
                    );
                }

                usleep(20000);
            } while (microtime(true) < $deadline);

            if (! $blocked) {
                throw new RuntimeException('The competing operation did not wait on the exam lock.');
            }

            $this->workers[$a]['input']->write("release\n");
            $this->workers[$a]['input']->close();

            // Drain both workers while they finish; either may emit stderr.
            $deadline = microtime(true) + 18;
            do {
                $running = false;
                foreach ([$a, $b] as $index) {
                    $process = $this->workers[$index]['process'];
                    $running = $process->isRunning() || $running;
                    $process->getOutput();
                    $process->getErrorOutput();
                    $process->checkTimeout();
                }

                if (! $running) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);

            if ($running) {
                $activity = DB::select(
                    'select pid, state, wait_event_type, wait_event, pg_blocking_pids(pid) as blockers
                     from pg_stat_activity where pid in (?, ?)',
                    [$readyA['pid'], $readyB['pid']]
                );
                throw new RuntimeException(
                    'Workers did not finish after release: '.json_encode([
                        'activity' => $activity,
                        'first_events' => $this->messages($a),
                        'second_events' => $this->messages($b),
                    ], JSON_THROW_ON_ERROR)
                );
            }

            return [
                'first' => $this->finish($a),
                'second' => $this->finish($b),
                'contention_verified' => true,
            ];
        } finally {
            foreach ($this->workers as $worker) {
                $worker['input']->close();
                if ($worker['process']->isRunning()) {
                    $worker['process']->stop(1);
                }
            }
            $this->workers = [];
        }
    }

    private function start(array $payload): int
    {
        $input = new InputStream;
        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/exam_concurrency_worker.php')],
            base_path(),
            ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false']
        );
        $process->setTimeout(25);
        $process->setInput($input);
        $process->start();
        $input->write(json_encode($payload, JSON_THROW_ON_ERROR)."\n");

        if (! ($payload['hold_lock'] ?? false)) {
            $input->close();
        }

        $index = count($this->workers);
        $this->workers[$index] = [
            'process' => $process,
            'input' => $input,
        ];

        return $index;
    }

    private function messages(int $index): array
    {
        $messages = [];

        foreach (explode("\n", $this->workers[$index]['process']->getOutput()) as $line) {
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded) && isset($decoded['event'])) {
                $messages[] = $decoded;
            }
        }

        return $messages;
    }

    private function awaitEvent(int $index, string $event): array
    {
        $deadline = microtime(true) + 7;

        do {
            $process = $this->workers[$index]['process'];
            $process->checkTimeout();

            foreach ($this->messages($index) as $message) {
                if ($message['event'] === $event) {
                    return $message;
                }
                if (in_array($message['event'], ['error', 'rejected'], true)) {
                    throw new RuntimeException(
                        'Worker could not reach '.$event.': '.json_encode($message)
                    );
                }
            }

            if (! $process->isRunning()) {
                throw new RuntimeException('Worker stopped before event: '.$event);
            }

            usleep(20000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Timed out waiting for worker event: '.$event);
    }

    private function finish(int $index): array
    {
        $process = $this->workers[$index]['process'];
        $process->wait();

        foreach (array_reverse($this->messages($index)) as $message) {
            if (in_array($message['event'], ['finished', 'rejected', 'error'], true)) {
                $message['exit_code'] = $process->getExitCode();

                return $message;
            }
        }

        throw new RuntimeException('Worker exited without an operation outcome.');
    }
}
