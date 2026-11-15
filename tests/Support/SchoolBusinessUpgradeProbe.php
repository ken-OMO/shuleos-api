<?php

use App\Services\School\SchoolBusinessWriteAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\MainSubscriptionBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\SchoolBusinessTestDatabase;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
SchoolBusinessTestDatabase::assertIsolated();
$path = sys_get_temp_dir().'/shuleos-business-write-upgrade.json';
$tables = ['schools', 'school_subscriptions', 'subscription_packages', 'roles', 'role_permissions', 'users'];
if (($argv[1] ?? '') === 'prepare') {
    $ids = [];
    foreach (['active', 'grace', 'read_only', 'locked'] as $state) {
        $school = SchoolBuilder::create(['lifecycle_state' => $state, 'timezone' => 'Africa/Nairobi']);
        MainSubscriptionBuilder::activeFor($school->id, $state === 'grace'
            ? ['status' => 'GRACE_PERIOD', 'grace_end_date' => now()->addYears(2)->toDateString()] : []);
        $ids[$state] = $school->id;
    }
    $missing = SchoolBuilder::create(['lifecycle_state' => 'active']);
    $ids['missing'] = $missing->id;
    $snapshot = [];
    foreach ($tables as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    file_put_contents($path, json_encode(['ids' => $ids, 'snapshot' => $snapshot], JSON_THROW_ON_ERROR));
    chmod($path, 0600);
    echo "Existing active/grace/read-only/locked and unentitled schools prepared without production data.\n";
} elseif (($argv[1] ?? '') === 'verify') {
    $before = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    foreach ($before['snapshot'] as $table => $expected) {
        if (DB::table($table)->orderBy('id')->get()->toJson() !== $expected) {
            throw new RuntimeException('Migration changed existing '.$table.' rows.');
        }
    }
    foreach ($before['ids'] as $state => $schoolId) {
        try {
            $result = app(SchoolBusinessWriteAccess::class)->run($schoolId, fn () => true);
            if (! in_array($state, ['active', 'grace'], true) || $result !== true) {
                throw new RuntimeException('Upgrade incorrectly admitted a restricted school.');
            }
        } catch (AuthorizationException $exception) {
            if (in_array($state, ['active', 'grace'], true)) {
                throw $exception;
            }
        }
    }
    $migration = require database_path('migrations/2026_11_15_210000_serialize_main_subscription_changes.php');
    $migration->up();
    foreach ($before['snapshot'] as $table => $expected) {
        if (DB::table($table)->orderBy('id')->get()->toJson() !== $expected) {
            throw new RuntimeException('Migration rerun changed existing '.$table.' rows.');
        }
    }
    unlink($path);
    echo "Upgrade preserved all existing school/subscription/package/role/grant/user rows; active/grace permitted, read-only/locked/missing denied; rerun changed no data.\n";
} else {
    throw new RuntimeException('Expected prepare or verify.');
}
