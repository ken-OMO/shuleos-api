<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class StudentElectionConcurrencyRunner
{
    private array $workers = [];

    public function run(array $first, array $second, bool $expectBlocking = true, ?callable $beforeSecond = null, ?callable $afterBlocked = null): array
    {
        StudentElectionConcurrencyFixture::assertIsolated();
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Committed fixtures required; no wrapping test transaction.');
        }
        try {
            $a = $this->start($first, true);
            $pidA = $this->await($a, 'ready')['pid'];
            $this->await($a, 'locked');
            if ($beforeSecond !== null) {
                $beforeSecond();
            }
            $b = $this->start($second, false);
            $pidB = $this->await($b, 'ready')['pid'];
            if ($pidA === $pidB) {
                throw new RuntimeException('Workers share a PostgreSQL session.');
            }
            if ($expectBlocking) {
                $deadline = microtime(true) + 10;
                $blocked = false;
                do {
                    $row = DB::selectOne('SELECT ?::integer = ANY(pg_blocking_pids(?::integer)) AS blocked', [$pidA, $pidB]);
                    $blocked = in_array($row->blocked, [true, 1, '1', 't'], true);
                    if ($blocked) {
                        break;
                    }
                    if (! $this->workers[$b]['process']->isRunning()) {
                        throw new RuntimeException('Competitor finished without the required lock wait: '.json_encode($this->messages($b)));
                    }
                    usleep(20000);
                } while (microtime(true) < $deadline);
                if (! $blocked) {
                    throw new RuntimeException('Actual PostgreSQL blocking was not observed.');
                }
                if ($afterBlocked !== null) {
                    $afterBlocked();
                }
            } else {
                // A distinct learner must commit while A still holds its open
                // transaction. This would fail under the old election UPDATE lock.
                $outcome = $this->await($b, 'outcome');
                if ($outcome['status'] !== 'committed' || ! $this->workers[$a]['process']->isRunning()) {
                    throw new RuntimeException('Independent vote did not commit while the first transaction remained held.');
                }
            }
            $this->workers[$a]['input']->write("release\n");
            $this->workers[$a]['input']->close();

            return ['first' => $this->finish($a), 'second' => $this->finish($b),
                'separate_sessions' => true, 'blocking_verified' => $expectBlocking,
                'parallel_commit_verified' => ! $expectBlocking];
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

    private function start(array $job, bool $hold): int
    {
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/student_election_concurrency_worker.php')],
            base_path(), ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false']);
        $process->setTimeout(35);
        $process->setInput($input);
        $process->start();
        // Credentials travel through stdin, never command-line arguments or logs.
        $input->write(json_encode([...$job, 'hold' => $hold, 'connection' => DB::connection()->getConfig()], JSON_THROW_ON_ERROR)."\n");
        if (! $hold) {
            $input->close();
        }
        $index = count($this->workers);
        $this->workers[$index] = compact('input', 'process');

        return $index;
    }

    private function messages(int $index): array
    {
        $messages = [];
        foreach (explode("\n", $this->workers[$index]['process']->getOutput()) as $line) {
            $message = json_decode($line, true);
            if (is_array($message) && isset($message['event'])) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function await(int $index, string $event): array
    {
        $deadline = microtime(true) + 12;
        do {
            $process = $this->workers[$index]['process'];
            $process->checkTimeout();
            foreach ($this->messages($index) as $message) {
                if ($message['event'] === $event) {
                    return $message;
                }
                if ($message['event'] === 'outcome') {
                    throw new RuntimeException('Worker ended before '.$event.': '.json_encode($message));
                }
            }
            if (! $process->isRunning()) {
                throw new RuntimeException('Worker stopped before '.$event.': '.$process->getErrorOutput());
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Worker timed out awaiting '.$event);
    }

    private function finish(int $index): array
    {
        $outcome = $this->await($index, 'outcome');
        $process = $this->workers[$index]['process'];
        $process->wait();
        if ($process->getExitCode() !== 0 || $outcome['status'] === 'error') {
            throw new RuntimeException('Worker failed: '.json_encode($outcome).' '.$process->getErrorOutput());
        }

        return $outcome;
    }
}
