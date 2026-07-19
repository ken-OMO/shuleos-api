<?php

namespace App\Services\Administrator;

use App\Core\Security\File\FilePolicy;
use App\Core\Security\File\FileQuarantine;
use App\Core\Security\File\FileSecurityManager;
use App\Core\Security\File\SecureFileStorage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdministratorImportService
{
    private const HEADERS = [
        'users' => ['username', 'email', 'phone', 'first_name', 'middle_name', 'last_name', 'role'],
        'teachers' => ['teacher_number', 'email', 'phone', 'first_name', 'middle_name', 'last_name', 'gender'],
        'learners' => ['admission_number', 'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth', 'grade', 'stream'],
        'parents' => ['email', 'phone', 'first_name', 'middle_name', 'last_name', 'learner_admission_number', 'relationship'],
        'guardians' => ['email', 'phone', 'first_name', 'middle_name', 'last_name', 'learner_admission_number', 'relationship'],
        'grades' => ['grade_name', 'education_level'],
        'streams' => ['stream_name', 'grade'],
        'learning_areas' => ['learning_area_name', 'code', 'education_level'],
    ];

    public function __construct(
        private AdministratorPortalAccessService $access,
        private AdministratorAuditService $audit,
        private FileSecurityManager $security,
        private FileQuarantine $quarantine,
        private SecureFileStorage $storage,
    ) {}

    public function index(User $user): mixed
    {
        $schoolId = $this->access->require($user, 'manage_data_imports')['school_id'];

        return DB::table('administrator_imports')->where('school_id', $schoolId)->select($this->safeColumns())->latest()->paginate(25);
    }

    public function preview(User $user, UploadedFile $file, string $type, string $idempotencyKey): array
    {
        $schoolId = $this->access->require($user, 'manage_data_imports')['school_id'];
        abort_unless(isset(self::HEADERS[$type]), 422, 'Unsupported import type.');
        if (strtolower($file->getClientOriginalExtension()) !== 'csv') {
            throw ValidationException::withMessages(['file' => 'Only CSV imports are accepted.']);
        }
        $policy = new FilePolicy('Administrator CSV Import', ['csv'], ['text/csv', 'text/plain', 'application/csv'], 5 * 1024 * 1024, 5000, 30, false, false, true, true, true, true, true, true, false, false);
        $report = $this->security->scan($file, $policy);
        if ($report->failed()) {
            throw ValidationException::withMessages(['file' => 'The CSV failed the secure file validation pipeline.']);
        }
        [$headers, $rows, $errors] = $this->inspectCsv($file, self::HEADERS[$type]);
        $keyHash = hash('sha256', $idempotencyKey);
        $existing = DB::table('administrator_imports')->where('school_id', $schoolId)->where('created_by', $user->id)->where('idempotency_key_hash', $keyHash)->first();
        if ($existing) {
            return $this->safe($existing) + ['idempotent_replay' => true];
        }

        $quarantineId = $this->quarantine->quarantine($file);
        $stored = $this->storage->storeFromQuarantine($this->quarantine, $quarantineId);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $schoolId, $user, $type, $keyHash, $stored, $headers, $rows, $errors) {
            DB::table('administrator_imports')->insert([
                'id' => $id, 'school_id' => $schoolId, 'created_by' => $user->id, 'import_type' => $type,
                'idempotency_key_hash' => $keyHash, 'storage_id' => $stored['storage_id'], 'source_hash' => $stored['source_hash'],
                'status' => 'previewed', 'header_snapshot' => json_encode($headers), 'total_rows' => count($rows),
                'valid_rows' => count($rows) - count($errors), 'invalid_rows' => count($errors), 'processed_rows' => 0,
                'previewed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_slice($errors, 0, 500) as $error) {
                DB::table('administrator_import_errors')->insert(['id' => (string) Str::uuid(), 'school_id' => $schoolId, 'import_id' => $id, 'row_number' => $error['row'], 'field' => $error['field'], 'error_code' => $error['code'], 'safe_message' => $error['message'], 'created_at' => now()]);
            }
        });
        $this->audit->record($user, 'administrator_import_previewed', 'administrator_imports', $id, [], ['import_type' => $type, 'total_rows' => count($rows), 'invalid_rows' => count($errors)]);

        return $this->show($user, $id);
    }

    public function queue(User $user, string $id): array
    {
        $import = $this->owned($user, $id);
        abort_unless($import->status === 'previewed', 409, 'Only a previewed import may be queued.');
        abort_if($import->invalid_rows > 0, 422, 'Resolve preview errors before queuing the import.');
        DB::table('administrator_imports')->where('id', $id)->update(['status' => 'queued', 'updated_at' => now()]);
        $this->audit->record($user, 'administrator_import_queued', 'administrator_imports', $id);

        return $this->show($user, $id);
    }

    public function show(User $user, string $id): array
    {
        return $this->safe($this->owned($user, $id));
    }

    public function errors(User $user, string $id): mixed
    {
        $this->owned($user, $id);

        return DB::table('administrator_import_errors')->where('import_id', $id)->select('id', 'row_number', 'field', 'error_code', 'safe_message', 'created_at')->orderBy('row_number')->paginate(100);
    }

    public function cancel(User $user, string $id): array
    {
        $import = $this->owned($user, $id);
        abort_unless(in_array($import->status, ['previewed', 'queued'], true), 409, 'This import can no longer be cancelled.');
        DB::table('administrator_imports')->where('id', $id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
        $this->audit->record($user, 'administrator_import_cancelled', 'administrator_imports', $id);

        return $this->show($user, $id);
    }

    public function processQueued(int $limit = 25): int
    {
        $count = 0;
        DB::table('administrator_imports')->join('schools', 'schools.id', '=', 'administrator_imports.school_id')->where('administrator_imports.status', 'queued')->where('schools.active', true)->whereNotIn('schools.lifecycle_state', ['suspended', 'locked', 'archived'])->select('administrator_imports.id')->limit(min($limit, 100))->get()->each(function ($row) use (&$count) {
            DB::table('administrator_imports')->where('id', $row->id)->update(['status' => 'validated', 'started_at' => now(), 'completed_at' => now(), 'updated_at' => now()]);
            $count++;
        });

        return $count;
    }

    private function inspectCsv(UploadedFile $file, array $allowed): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        abort_unless($handle, 422, 'CSV could not be read.');
        $headers = array_map(fn ($value) => Str::snake(trim((string) $value)), fgetcsv($handle) ?: []);
        if (! $headers || array_diff($headers, $allowed) || in_array('password', $headers, true) || count($headers) !== count(array_unique($headers))) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'CSV headers are invalid, duplicated, or include prohibited fields.']);
        }
        $rows = [];
        $errors = [];
        $rowNumber = 1;
        while (($row = fgetcsv($handle)) !== false && count($rows) < 5000) {
            $rowNumber++;
            $rows[] = $row;
            if (count($row) !== count($headers)) {
                $errors[] = ['row' => $rowNumber, 'field' => null, 'code' => 'column_count', 'message' => 'Row has an unexpected number of columns.'];

                continue;
            }
            foreach ($row as $index => $value) {
                if (preg_match('/^[=+@\-]/', ltrim((string) $value))) {
                    $errors[] = ['row' => $rowNumber, 'field' => $headers[$index], 'code' => 'formula_not_allowed', 'message' => 'Spreadsheet formulas are not permitted.'];
                    break;
                }
            }
        }
        fclose($handle);

        return [$headers, $rows, $errors];
    }

    private function owned(User $user, string $id): object
    {
        $schoolId = $this->access->require($user, 'manage_data_imports')['school_id'];

        return DB::table('administrator_imports')->where('id', $id)->where('school_id', $schoolId)->firstOrFail();
    }

    private function safe(object $row): array
    {
        return collect((array) $row)->only($this->safeColumns())->all();
    }

    private function safeColumns(): array
    {
        return ['id', 'import_type', 'status', 'header_snapshot', 'total_rows', 'valid_rows', 'invalid_rows', 'processed_rows', 'previewed_at', 'started_at', 'completed_at', 'cancelled_at', 'created_at', 'updated_at'];
    }
}
