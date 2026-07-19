<?php

namespace App\Services\Administrator\Operations;

use App\Models\User;

class AdministratorLogService
{
    public function __construct(private AdministratorOperationsAccessService $access) {}

    public function index(User $user): array
    {
        $this->access->platform($user, 'view_application_logs');

        return collect(config('administrator_operations.log_files'))->map(fn ($file, $key) => ['id' => $key, 'available' => is_file(storage_path('logs/'.$file)), 'entries_endpoint' => '/api/admin/operations/logs/'.$key.'/entries'])->values()->all();
    }

    public function show(User $user, string $id): array
    {
        $log = collect($this->index($user))->firstWhere('id', $id);

        return $log ?: abort(404);
    }

    public function entries(User $user, string $id, int $limit = 100): array
    {
        $this->show($user, $id);
        $file = config('administrator_operations.log_files.'.$id);
        abort_unless(is_string($file), 404);
        $path = storage_path('logs/'.$file);
        if (! is_file($path)) {
            return [];
        }
        $handle = fopen($path, 'rb');
        if (! $handle) {
            return [];
        }
        $size = filesize($path) ?: 0;
        fseek($handle, max(0, $size - 262144));
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);
        $lines = array_slice(preg_split('/\R/', $content) ?: [], -min(max($limit, 1), 200));

        return collect($lines)->filter()->map(fn ($line) => ['timestamp' => preg_match('/\[(.*?)\]/', $line, $m) ? $m[1] : null, 'severity' => preg_match('/\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY):/i', $line, $m) ? strtolower($m[1]) : 'unknown', 'message' => $this->redact($line)])->values()->all();
    }

    private function redact(string $line): string
    {
        $line = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $line);
        $line = preg_replace('/(?:\+?254|0)7\d{8}/', '[redacted-phone]', $line);
        $line = preg_replace('/(?:password|token|secret|authorization|passkey|api[_-]?key)\s*[=:]\s*[^\s,;]+/i', '$1=[redacted]', $line);
        $line = preg_replace('/Bearer\s+[A-Za-z0-9._~-]+/i', 'Bearer [redacted]', $line);

        return str(strip_tags($line))->limit(1000, '')->toString();
    }
}
