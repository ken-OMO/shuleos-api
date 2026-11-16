<?php

declare(strict_types=1);

use App\Models\StudentElection;
use App\Models\User;
use App\Services\StudentElection\StudentElectionApplicationService;
use App\Services\StudentElection\StudentElectionCancellationService;
use App\Services\StudentElection\StudentElectionPhotoService;
use App\Services\StudentElection\StudentElectionService;
use App\Services\StudentElection\StudentElectionStationService;
use App\Services\StudentElection\StudentElectionVotingService;
use App\Services\StudentElectionSubscription\StudentElectionCommercialAccess;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\StudentElectionApplicationRaceRollback;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function electionApplicationRaceMessage(array $data): void
{
    echo json_encode($data, JSON_THROW_ON_ERROR).PHP_EOL;
    flush();
}

try {
    $input = fgets(STDIN);
    if ($input === false) {
        throw new RuntimeException('Missing isolated worker input.');
    }
    $job = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
    $connection = $job['connection'];
    if (($connection['driver'] ?? null) !== 'pgsql' || ($connection['database'] ?? null) !== 'shuleos_assessment_fk_test' || ! empty($connection['url'])) {
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
    config(['database.connections.'.$name => $connection, 'boarding_reporting.scheduler_enabled' => false,
        'transport_reporting.scheduler_enabled' => false, 'student_election_stations.enabled' => false,
        'telescope.enabled' => false, 'logging.default' => 'stderr']);
    if ($job['operation'] === 'station_submit') {
        config(['student_election_stations.enabled' => true]);
    }
    DB::purge($name);
    $actual = DB::selectOne('SELECT current_database() AS name, pg_backend_pid() AS pid', [], false);
    if ($actual->name !== 'shuleos_assessment_fk_test') {
        throw new RuntimeException('Unexpected actual database.');
    }
    DB::statement("SET lock_timeout = '20s'");
    DB::statement("SET statement_timeout = '25s'");
    Carbon::setTestNow($job['clock']);
    CarbonImmutable::setTestNow($job['clock']);
    electionApplicationRaceMessage(['event' => 'ready', 'pid' => (int) $actual->pid]);
    if (isset($job['after_wait_clock'])) {
        $advanced = false;
        DB::listen(function (QueryExecuted $event) use ($job, &$advanced): void {
            if (! $advanced && str_starts_with($event->sql, 'select') && str_contains($event->sql, '"student_elections"') && str_contains($event->sql, 'for update')) {
                // QueryExecuted fires AFTER the required lock wait has completed.
                $advanced = true;
                Carbon::setTestNow($job['after_wait_clock']);
                CarbonImmutable::setTestNow($job['after_wait_clock']);
            }
        });
    }
    if (isset($job['after_commercial_wait_clock'])) {
        $commercialAdvanced = false;
        DB::listen(function (QueryExecuted $event) use ($job, &$commercialAdvanced): void {
            if (! $commercialAdvanced && str_starts_with($event->sql, 'select') && str_contains($event->sql, '"school_subscriptions"') && str_contains($event->sql, 'for share')) {
                $commercialAdvanced = true;
                Carbon::setTestNow($job['after_commercial_wait_clock']);
                CarbonImmutable::setTestNow($job['after_commercial_wait_clock']);
            }
        });
    }
    DB::beginTransaction();
    $actor = User::withoutGlobalScopes()->where('school_id', $job['school'])->findOrFail($job['actor']);
    auth()->guard()->setUser($actor);
    $service = app(StudentElectionApplicationService::class);
    switch ($job['operation']) {
        case 'photo_profile_read':
            app(StudentElectionPhotoService::class)->profile($actor, $job['learner']);
            break;
        case 'photo_learner_write':
            DB::table('learners')->where('school_id', $job['school'])->where('id', $job['learner'])->lockForUpdate()->firstOrFail();
            break;
        case 'photo_learner_revoke':
            DB::table('learners')->where('school_id', $job['school'])->where('id', $job['learner'])->update(['portal_enabled' => false]);
            break;
        case 'cancel_propose':
            app(StudentElectionCancellationService::class)->propose($actor, $job['election'], 'Independently documented cancellation incident.', true);
            break;
        case 'cancel_decide':
            app(StudentElectionCancellationService::class)->decide($actor, $job['election'], $job['proposal'], true, 'Independent review confirms this election must be cancelled.', true);
            break;
        case 'cancel_fresh':
            app(StudentElectionCancellationService::class)->fresh($actor, $job['election'], $job['data'], true);
            break;
        case 'submit':
            $service->submit($actor, $job['election'], $job['expected_version'] ?? null, $job['data']);
            break;
        case 'consent':
        case 'withdraw':
            $service->consent($actor, $job['election'], $job['application'], $job['version'], $job['designation'], $job['operation'] === 'withdraw' ? 'withdraw' : 'accept');
            break;
        case 'review':
            $service->review($actor, $job['election'], $job['application'], $job['version'], true, 'Independent review of the exact version; no conflict declared.', false, true);
            break;
        case 'vote':
            app(StudentElectionVotingService::class)->vote($actor, $job['election'], $job['ep'], $job['candidate']);
            break;
        case 'station_submit':
            app(StudentElectionStationService::class)->submit($job['credential'], $job['session'], $job['digest'], $job['answers']);
            break;
        case 'publish':
            app(StudentElectionService::class)->publish($job['school'], $job['election'], $actor->id);
            break;
        case 'hold_election':
            DB::table('student_elections')->where('school_id', $job['school'])->where('id', $job['election'])->lockForUpdate()->firstOrFail();
            break;
        case 'commercial_hold_main':
            DB::table('school_subscriptions')->where('school_id', $job['school'])->orderByDesc('created_at')->orderByDesc('id')->lockForUpdate()->firstOrFail();
            break;
        case 'commercial_main_expire':
            $main = DB::table('school_subscriptions')->where('school_id', $job['school'])->orderByDesc('created_at')->orderByDesc('id')->lockForUpdate()->firstOrFail();
            DB::table('school_subscriptions')->where('id', $main->id)->update(['status' => 'ACTIVE', 'expiry_date' => DB::raw('start_date'), 'grace_end_date' => null]);
            break;
        case 'commercial_new_election':
            app(StudentElectionService::class)->create($job['school'], $job['data'], $actor->id);
            break;
        case 'commercial_live':
            $root = StudentElection::current()->where('school_id', $job['school'])->whereKey($job['election'])->sharedLock()->firstOrFail();
            app(StudentElectionCommercialAccess::class)->live($root);
            break;
        default:
            throw new RuntimeException('Unknown isolated race operation.');
    }
    if ($job['hold'] ?? false) {
        electionApplicationRaceMessage(['event' => 'locked']);
        if (trim((string) fgets(STDIN)) !== 'release') {
            throw new RuntimeException('Worker release was not received.');
        }
    }
    if ($job['rollback'] ?? false) {
        throw new StudentElectionApplicationRaceRollback('Deliberate outer rollback.');
    }
    DB::commit();
    electionApplicationRaceMessage(['event' => 'outcome', 'status' => 'committed']);
} catch (Throwable $error) {
    if (isset($app)) {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
    electionApplicationRaceMessage(['event' => 'outcome',
        'status' => $error instanceof StudentElectionApplicationRaceRollback ? 'rolled_back'
            : (($error instanceof ValidationException || $error instanceof HttpException || $error instanceof AuthorizationException) ? 'rejected' : 'error'),
        'fields' => $error instanceof ValidationException ? array_keys($error->errors()) : [],
        'http_status' => $error instanceof HttpException ? $error->getStatusCode() : null,
        'error_class' => get_class($error)]);
}
