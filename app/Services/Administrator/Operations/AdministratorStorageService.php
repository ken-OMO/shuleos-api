<?php

namespace App\Services\Administrator\Operations;

use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use Illuminate\Support\Facades\DB;

class AdministratorStorageService
{
    public function __construct(
        private AdministratorOperationsAccessService $access,
        private AdministratorAuditService $audit
    ) {}

    public function summary(
        User $user,
        string $view = 'summary'
    ): mixed {
        $this->access->platform(
            $user,
            'view_storage_operations'
        );

        if ($view === 'disks') {
            return [
                [
                    'disk' => 'private',
                    'writable' => is_writable(
                        storage_path('app')
                    ),
                    'public' => false,
                ],
                [
                    'disk' => 'quarantine',
                    'writable' => is_writable(
                        storage_path(
                            'app/private/quarantine'
                        )
                    ),
                    'public' => false,
                ],
            ];
        }

        if ($view === 'quarantine') {
            return DB::table(
                'administrator_storage_records'
            )
                ->where('status', 'quarantined')
                ->select($this->safe())
                ->latest()
                ->limit(100)
                ->get();
        }

        if ($view === 'orphans') {
            return DB::table(
                'administrator_storage_records'
            )
                ->where('status', 'orphaned')
                ->select($this->safe())
                ->latest()
                ->limit(100)
                ->get();
        }

        return [
            'records' => DB::table(
                'administrator_storage_records'
            )
                ->selectRaw(
                    'status, COUNT(*) AS total'
                )
                ->groupBy('status')
                ->pluck('total', 'status'),

            'private_storage_writable' => is_writable(
                storage_path('app')
            ),

            'raw_paths_exposed' => false,

            'physical_deletion_available' => false,
        ];
    }

    public function action(
        User $user,
        string $id,
        string $action,
        ?string $reason
    ): array {
        $this->access->platform(
            $user,
            'manage_quarantined_files'
        );

        $record = DB::table(
            'administrator_storage_records'
        )
            ->where('id', $id)
            ->firstOrFail();

        if (
            $action === 'release'
            && $record->status !== 'quarantined'
        ) {
            abort(
                409,
                'Only quarantined files may be released.'
            );
        }

        if (
            $action === 'release'
            && $record->scanner_state !== 'clean'
        ) {
            abort(
                409,
                'Only scanner-confirmed clean files may be released.'
            );
        }

        if (
            $action === 'reject'
            && $record->status !== 'quarantined'
        ) {
            abort(
                409,
                'Only quarantined files may be rejected.'
            );
        }

        if (
            $action === 'archive'
            && $record->status !== 'orphaned'
        ) {
            abort(
                409,
                'Only orphaned files may be archived.'
            );
        }

        if (
            in_array(
                $action,
                ['reject', 'archive'],
                true
            )
            && blank($reason)
        ) {
            abort(
                422,
                'A review reason is required.'
            );
        }

        $status = match ($action) {
            'release' => 'released',
            'reject' => 'rejected',
            'archive' => 'archived',
            default => abort(404),
        };

        DB::table(
            'administrator_storage_records'
        )
            ->where('id', $id)
            ->update([
                'status' => $status,
                'reviewed_by' => $user->id,
                'review_reason' => $reason
                    ? strip_tags($reason)
                    : null,
                'updated_at' => now(),
            ]);

        $this->audit->record(
            $user,
            'administrator_storage_'.$status,
            'administrator_storage_records',
            $id,
            [
                'status' => $record->status,
            ],
            [
                'status' => $status,
                'physical_delete' => false,
            ]
        );

        return [
            'id' => $id,
            'status' => $status,
            'physical_delete' => false,
        ];
    }

    private function safe(): array
    {
        return [
            'id',
            'record_type',
            'record_id',
            'status',
            'scanner_state',
            'safe_label',
            'size',
            'reviewed_by',
            'review_reason',
            'created_at',
            'updated_at',
        ];
    }
}
