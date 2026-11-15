<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\StudentElection\StudentElectionRoundService;
use App\Services\StudentElection\StudentElectionService;
use App\Services\StudentElection\StudentElectionStationService;
use App\Services\StudentElection\StudentElectionVotingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\StudentElectionTestRollback;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function studentElectionWorkerMessage(array $data): void
{
    echo json_encode($data, JSON_THROW_ON_ERROR).PHP_EOL;
    flush();
}

try {
    $line = fgets(STDIN);
    if ($line === false) {
        throw new RuntimeException('Missing test worker input.');
    }
    $job = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $connection = $job['connection'];
    if (($connection['driver'] ?? null) !== 'pgsql'
        || ($connection['database'] ?? null) !== 'shuleos_assessment_fk_test'
        || ! empty($connection['url'])) {
        throw new RuntimeException('Exact isolated worker database required.');
    }
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || $app->configurationIsCached()) {
        throw new RuntimeException('Uncached testing environment required.');
    }
    $name = config('database.default');
    config(['database.connections.'.$name => $connection,
        'boarding_reporting.scheduler_enabled' => false, 'transport_reporting.scheduler_enabled' => false,
        'telescope.enabled' => false, 'logging.default' => 'stderr']);
    if (str_starts_with($job['operation'], 'station_')) {
        config(['student_election_stations.enabled' => true, 'student_election_stations.origin' => 'https://school.test']);
    }
    DB::purge($name);
    $actual = DB::selectOne('SELECT current_database() AS name, pg_backend_pid() AS pid');
    if ($actual->name !== 'shuleos_assessment_fk_test') {
        throw new RuntimeException('Unexpected actual worker database.');
    }
    DB::statement("SET lock_timeout = '20s'");
    DB::statement("SET statement_timeout = '25s'");
    studentElectionWorkerMessage(['event' => 'ready', 'pid' => (int) $actual->pid]);

    DB::beginTransaction();
    $user = User::withoutGlobalScopes()->where('school_id', $job['school'])->findOrFail($job['actor']);
    auth()->guard()->setUser($user);
    if ($job['operation'] === 'vote') {
        app(StudentElectionVotingService::class)->vote($user, $job['election'], $job['ep'], $job['candidate']);
    } elseif ($job['operation'] === 'close') {
        app(StudentElectionService::class)->transition($job['school'], $job['election'], 'voting_closed', $user->id);
    } elseif ($job['operation'] === 'open') {
        app(StudentElectionService::class)->transition($job['school'], $job['election'], 'voting_open', $user->id);
    } elseif ($job['operation'] === 'review') {
        app(StudentElectionService::class)->review($job['school'], $job['candidate'], 'disqualified', $user->id);
    } elseif ($job['operation'] === 'tally') {
        app(StudentElectionService::class)->tally($job['school'], $job['election'], $user->id);
    } elseif ($job['operation'] === 'publish') {
        app(StudentElectionService::class)->publish($job['school'], $job['election'], $user->id);
    } elseif ($job['operation'] === 'round_create') {
        app(StudentElectionRoundService::class)->create($user, $job['election'], $job['ep'], $job['opens'], $job['closes']);
    } elseif ($job['operation'] === 'round_vote') {
        app(StudentElectionRoundService::class)->cast($user, $job['election'], $job['round'], $job['token'], $job['choice'], $job['candidate'] ?? null);
    } elseif ($job['operation'] === 'round_close') {
        app(StudentElectionRoundService::class)->transition($user, $job['election'], $job['round'], 'closed');
    } elseif ($job['operation'] === 'round_tally') {
        app(StudentElectionRoundService::class)->tally($user, $job['election'], $job['round']);
    } elseif ($job['operation'] === 'hold_round_voter') {
        DB::table('student_election_round_voters')->where('round_id', $job['round'])->where('learner_id', $job['learner'])->lockForUpdate()->firstOrFail();
    } elseif ($job['operation'] === 'station_submit') {
        app(StudentElectionStationService::class)->submit($job['credential'], $job['session'], $job['digest'], $job['answers']);
    } elseif ($job['operation'] === 'station_request') {
        app(StudentElectionStationService::class)->request($job['credential'], $job['admission']);
    } elseif ($job['operation'] === 'station_reset') {
        app(StudentElectionStationService::class)->reset($user, $job['election'], $job['station']);
    } elseif ($job['operation'] === 'station_revoke') {
        app(StudentElectionStationService::class)->revoke($user, $job['election'], $job['station']);
    } elseif ($job['operation'] === 'station_hold_voter') {
        DB::table('student_election_voters')->where('id', $job['voter'])->lockForUpdate()->firstOrFail();
    } elseif ($job['operation'] === 'station_hold_learner') {
        DB::table('learners')->where('id', $job['learner'])->lockForUpdate()->firstOrFail();
    } elseif ($job['operation'] === 'station_attendance_change') {
        DB::table('attendance_registers')->where('id', $job['register'])->lockForUpdate()->firstOrFail();
        DB::table('learner_attendance')->where('id', $job['attendance'])->update(['finalized' => false]);
    } elseif ($job['operation'] === 'hold_voter') {
        DB::table('student_election_voters')->where('school_id', $job['school'])
            ->where('id', $job['voter'])->lockForUpdate()->firstOrFail();
    } else {
        throw new RuntimeException('Unknown test operation.');
    }
    if ($job['hold'] ?? false) {
        studentElectionWorkerMessage(['event' => 'locked']);
        if (trim((string) fgets(STDIN)) !== 'release') {
            throw new RuntimeException('Worker release was not received.');
        }
    }
    if ($job['rollback'] ?? false) {
        throw new StudentElectionTestRollback('Deliberate outer-transaction rollback.');
    }
    DB::commit();
    studentElectionWorkerMessage(['event' => 'outcome', 'status' => 'committed']);
} catch (Throwable $exception) {
    if (isset($app)) {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
    studentElectionWorkerMessage(['event' => 'outcome',
        'status' => $exception instanceof ValidationException ? 'rejected'
            : ($exception instanceof StudentElectionTestRollback ? 'rolled_back' : 'error'),
        'fields' => $exception instanceof ValidationException ? array_keys($exception->errors()) : [],
        'error_class' => get_class($exception),
    ]);
}
