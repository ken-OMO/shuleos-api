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
            'publish_report_cards' => 'Publish generated report cards',
            'manage_assessment_registrations' => 'Create, update and delete assessment registrations',
            'manage_mark_entry_permissions' => 'Grant, update and revoke mark-entry permissions',
            'publish_merit_lists' => 'Publish generated merit lists',
            'update_report_card_comments' => 'Edit comments on unpublished report cards',
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
        // Preserve deployed permission identities and explicit role assignments.
    }
};
