<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\UserBuilder;

// CLI-only CI probe; no HTTP route and no production fixture data.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'shuleos_assessment_fk_test'
    || ! in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost'], true)
    || is_file(base_path('bootstrap/cache/config.php'))) {
    throw new RuntimeException('Upgrade probe requires an isolated PostgreSQL test database.');
}
$path = sys_get_temp_dir().'/shuleos-portal-entry-upgrade.json';
if (($argv[1] ?? '') === 'prepare') {
    $school = SchoolBuilder::create();
    $other = SchoolBuilder::create();
    $teacher = RoleBuilder::create(['role_name' => 'Teacher']);
    $custom = RoleBuilder::create(['role_name' => 'Portal upgrade custom '.Str::uuid(), 'school_id' => $school->id, 'system_role' => false]);
    UserBuilder::create($school, $teacher);
    UserBuilder::create($other, RoleBuilder::create(['role_name' => 'Parent']));
    UserBuilder::create($school, $custom);
    $permission = DB::table('permissions')->where('permission_name', 'view_own_teacher_assignments')->soleValue('id');
    // Deliberately model a partial existing upgrade with a legitimate prior grant.
    foreach ([$teacher->id, $custom->id] as $roleId) {
        DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $roleId, 'permission_id' => $permission, 'created_at' => now()]);
    }
    $snapshot = [];
    foreach (['roles', 'role_permissions', 'users'] as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->keyBy('id')->all();
    }
    file_put_contents($path, json_encode($snapshot, JSON_THROW_ON_ERROR));
    chmod($path, 0600);
    echo "Existing-state fixture prepared with prior grants, custom role and users in two schools.\n";
} elseif (($argv[1] ?? '') === 'verify') {
    $before = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    foreach ($before as $table => $rows) {
        foreach ($rows as $id => $expected) {
            $actual = DB::table($table)->where('id', $id)->first();
            if (! $actual || (array) $actual !== $expected) {
                throw new RuntimeException('Upgrade modified an existing '.$table.' row.');
            }
        }
    }
    if (DB::table('role_permissions')->count() !== count($before['role_permissions']) + 14) {
        throw new RuntimeException('Upgrade did not add exactly the fourteen remaining allowlisted grants.');
    }
    $migration = require database_path('migrations/2026_11_15_160000_backfill_portal_entry_permissions.php');
    $once = DB::table('role_permissions')->orderBy('id')->get()->toJson();
    $migration->up();
    if ($once !== DB::table('role_permissions')->orderBy('id')->get()->toJson()) {
        throw new RuntimeException('Upgrade rerun was not a no-op.');
    }
    unlink($path);
    echo "Existing-state upgrade preserved every prior row, added exactly fourteen missing grants and reran without changes.\n";
} else {
    throw new RuntimeException('Expected prepare or verify.');
}
