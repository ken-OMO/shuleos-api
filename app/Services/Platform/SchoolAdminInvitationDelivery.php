<?php

namespace App\Services\Platform;

use App\Mail\SchoolAdminInvitationMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SchoolAdminInvitationDelivery
{
    /** A successful transport call is acceptance, not proof of inbox delivery. */
    public function send(array $onboarding): string
    {
        $admin = $onboarding['initial_admin'];

        try {
            Mail::to($admin['email'])->send(new SchoolAdminInvitationMail(
                $onboarding['school']['school_name'],
                $onboarding['school']['school_code'],
                $admin['username'],
                $admin['temporary_password'],
                rtrim((string) config('communication.frontend_url'), '/').'/login',
                $admin['temporary_password_expires_at']->copy()->utc()->toDateTimeString(),
            ));

            return 'accepted';
        } catch (Throwable) {
            // Never log provider exceptions, email contents, credentials or tokens.
            try {
                Log::warning('School administrator invitation delivery failed.', [
                    'school_id' => $onboarding['school']['id'],
                    'user_id' => $admin['id'],
                ]);
            } catch (Throwable) {
                // Optional diagnostics must not disguise a committed registration.
            }

            return 'failed';
        }
    }
}
