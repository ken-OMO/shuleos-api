<?php

namespace App\Console\Commands;

use App\Services\Platform\PlatformOwnerBootstrapService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

class BootstrapPlatformOwner extends Command
{
    protected $signature = 'shuleos:bootstrap-platform-owner
                            {--email= : Platform Owner email address}
                            {--username= : Internal Platform Owner username}
                            {--first-name= : Platform Owner first name}
                            {--last-name= : Platform Owner last name}
                            {--yes : Skip the final interactive confirmation}
                            {--force : Explicitly allow execution in production}';

    protected $description = 'Securely bootstrap the single root ShuleOS Platform Owner identity';

    public function handle(
        PlatformOwnerBootstrapService $bootstrap
    ): int {
        /*
         * Production execution must be deliberate.
         */
        if (
            app()->environment('production')
            && ! $this->option('force')
        ) {
            $this->error(
                'Production bootstrap requires the explicit --force option.'
            );

            return self::FAILURE;
        }

        $email = trim(
            (string) (
                $this->option('email')
                ?: $this->ask(
                    'Platform Owner email'
                )
            )
        );

        $username = trim(
            (string) (
                $this->option('username')
                ?: $this->ask(
                    'Platform Owner username',
                    'platform.owner'
                )
            )
        );

        $firstName = trim(
            (string) (
                $this->option('first-name')
                ?: $this->ask(
                    'First name'
                )
            )
        );

        $lastName = trim(
            (string) (
                $this->option('last-name')
                ?: $this->ask(
                    'Last name'
                )
            )
        );

        /*
         * Password is deliberately NOT accepted as an Artisan option.
         *
         * Passing secrets through command-line arguments may expose
         * them through shell history, process inspection, CI logs,
         * terminal recordings, or deployment scripts.
         */
        $temporaryPassword = (string) $this->secret(
            'Temporary password (minimum 16 characters, upper/lowercase, number and symbol)'
        );

        if ($temporaryPassword === '') {
            $this->error(
                'A temporary password is required.'
            );

            return self::FAILURE;
        }

        $confirmation = (string) $this->secret(
            'Confirm temporary password'
        );

        if (! hash_equals(
            $temporaryPassword,
            $confirmation
        )) {
            $this->error(
                'Password confirmation does not match.'
            );

            return self::FAILURE;
        }

        if (
            ! $this->option('yes')
            && ! $this->confirm(
                'Create the root Platform Owner identity?',
                false
            )
        ) {
            $this->warn(
                'Platform Owner bootstrap cancelled.'
            );

            return self::FAILURE;
        }

        try {
            $user = $bootstrap->bootstrap(
                [
                    'email' => $email,
                    'username' => $username,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ],
                $temporaryPassword
            );
        } catch (ValidationException $exception) {
            foreach (
                $exception->errors() as $field => $messages
            ) {
                foreach ($messages as $message) {
                    $this->error(
                        "{$field}: {$message}"
                    );
                }
            }

            return self::FAILURE;
        } catch (Throwable $exception) {
            /*
             * Preserve internal diagnostics without exposing exception
             * messages, SQL, paths, connection details, hashes, or
             * environment values to ordinary command output.
             */
            report($exception);

            $this->error(
                'Platform Owner bootstrap failed.'
            );

            return self::FAILURE;
        } finally {
            /*
             * Avoid retaining duplicate references longer than needed.
             * PHP strings cannot be reliably memory-zeroed here, but
             * we deliberately discard our local references.
             */
            unset(
                $temporaryPassword,
                $confirmation
            );
        }

        $this->newLine();

        $this->info(
            'Platform Owner bootstrapped successfully.'
        );

        $this->line(
            'User ID: '.$user->id
        );

        $this->line(
            'Username: '.$user->username
        );

        $this->line(
            'Email: '.$user->email
        );

        $this->line(
            'Tenant scope: PLATFORM'
        );

        $this->newLine();

        $this->warn(
            'The email is intentionally not yet marked verified.'
        );

        $this->warn(
            'The temporary password expires after 24 hours and must be changed during first-login activation.'
        );

        $this->warn(
            'Do not store the temporary password in source code, scripts, notes, logs, or chat.'
        );

        return self::SUCCESS;
    }
}
