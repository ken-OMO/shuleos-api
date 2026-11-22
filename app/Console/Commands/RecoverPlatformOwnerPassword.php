<?php

namespace App\Console\Commands;

use App\Services\Platform\PlatformOwnerPasswordRecovery;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

final class RecoverPlatformOwnerPassword extends Command
{
    protected $signature = 'shuleos:recover-platform-owner-password
                            {--force : Explicitly allow execution in production}';

    protected $description = 'Set a temporary password for the existing root Platform Owner';

    public function handle(PlatformOwnerPasswordRecovery $recovery): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Platform Owner recovery requires an interactive console.');

            return self::FAILURE;
        }

        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Production recovery requires the explicit --force option.');

            return self::FAILURE;
        }

        $email = (string) $this->ask('Existing Platform Owner email');
        $password = (string) $this->secret('New temporary password (16–72 bytes)', false);
        $confirmation = (string) $this->secret('Confirm temporary password', false);
        if ($password === '' || ! hash_equals($password, $confirmation)) {
            $this->error('Enter matching temporary passwords.');

            return self::FAILURE;
        }

        if (! $this->confirm('Replace the Platform Owner password and revoke existing sessions?')) {
            return self::FAILURE;
        }

        try {
            $recovery->recover($email, $password);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Recovery failed. Verify the owner email and account status.');

            return self::FAILURE;
        }

        $this->info('Temporary password set. Sign in within 24 hours and complete email verification and password activation.');

        return self::SUCCESS;
    }
}
