<?php

use App\Services\School\SchoolBusinessWriteAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\SchoolBusinessTestDatabase;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function businessWorkerMessage(array $message): void
{
    echo json_encode($message, JSON_THROW_ON_ERROR).PHP_EOL;
    flush();
}

try {
    $payload = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $connection = $payload['connection'];
    if (($connection['driver'] ?? null) !== 'pgsql'
        || ($connection['database'] ?? null) !== 'shuleos_assessment_fk_test'
        || ! in_array($connection['host'] ?? null, ['127.0.0.1', 'localhost'], true)
        || ! empty($connection['url'])) {
        throw new RuntimeException('An isolated PostgreSQL test connection is required.');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || $app->configurationIsCached()) {
        throw new RuntimeException('Uncached testing configuration required.');
    }
    $name = config('database.default');
    config(['database.connections.'.$name => $connection]);
    DB::purge($name);
    SchoolBusinessTestDatabase::assertIsolated();
    $actual = DB::selectOne('SELECT current_database() AS name, pg_backend_pid() AS pid');
    if ($actual->name !== 'shuleos_assessment_fk_test') {
        throw new RuntimeException('Unexpected actual database.');
    }
    DB::statement("SET lock_timeout = '10s'");
    DB::statement("SET statement_timeout = '15s'");
    businessWorkerMessage(['event' => 'ready', 'pid' => (int) $actual->pid]);
    $hold = function () use ($payload): void {
        if ($payload['hold_lock'] ?? false) {
            businessWorkerMessage(['event' => 'locked']);
            if (trim((string) fgets(STDIN)) !== 'release') {
                throw new RuntimeException('Worker was not released.');
            }
        }
    };
    DB::transaction(function () use ($payload, $hold): void {
        if ($payload['action'] === 'write') {
            app(SchoolBusinessWriteAccess::class)->run($payload['school_id'], function () use ($payload, $hold): void {
                $hold();
                DB::table('schools')->where('id', $payload['school_id'])->update(['short_name' => 'Written']);
            });
        } elseif ($payload['action'] === 'expire') {
            DB::table('school_subscriptions')->where('id', $payload['subscription_id'])->update(['expiry_date' => '2000-01-01']);
            $hold();
        } elseif ($payload['action'] === 'supersede') {
            DB::table('school_subscriptions')->insert($payload['subscription']);
            $hold();
        } elseif ($payload['action'] === 'restrict') {
            DB::table('schools')->where('id', $payload['school_id'])->update(['lifecycle_state' => 'read_only']);
            $hold();
        } else {
            throw new RuntimeException('Unsupported test operation.');
        }
    });
    businessWorkerMessage(['event' => 'finished']);
} catch (AuthorizationException $error) {
    businessWorkerMessage(['event' => 'rejected', 'status' => 403]);
} catch (Throwable $error) {
    businessWorkerMessage(['event' => 'error', 'class' => get_class($error), 'message' => $error->getMessage()]);
    exit(1);
}
