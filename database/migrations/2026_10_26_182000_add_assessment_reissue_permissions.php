<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'request_assessment_reissues' => 'Request corrections to published assessment outputs',
            'approve_assessment_reissues' => 'Approve or reject published assessment correction requests',
            'prepare_assessment_reissues' => 'Prepare corrected results and recalculated assessment outputs',
            'review_assessment_reissues' => 'Approve prepared assessment revisions for publication',
            'publish_assessment_reissues' => 'Publish approved assessment revisions',
            'view_assessment_publication_history' => 'View assessment publication versions and correction history',
        ] as $name => $description) {
            if (DB::table('permissions')->where('permission_name', $name)->exists()) {
                continue;
            }

            DB::table('permissions')->insert([
                'id' => (string) Str::uuid(),
                'permission_name' => $name,
                'module_name' => 'assessment',
                'description' => $description,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Preserve deployed permission identities and role assignments.
    }
};
