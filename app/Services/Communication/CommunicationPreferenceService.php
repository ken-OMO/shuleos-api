<?php

namespace App\Services\Communication;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommunicationPreferenceService
{
    public function get(User $user): object
    {
        DB::table('communication_preferences')->insertOrIgnore(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'user_id' => $user->id, 'email_enabled' => true, 'sms_enabled' => false, 'in_app_enabled' => true, 'digest_frequency' => 'immediate', 'timezone' => 'Africa/Nairobi', 'language' => 'en', 'emergency_override' => true, 'marketing_opt_out' => false, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('communication_preferences')->where('school_id', $user->school_id)->where('user_id', $user->id)->first();
    }

    public function update(User $user, array $data): object
    {
        $preference = $this->get($user);
        $schoolSmsEnabled = DB::table('communication_policies')->where('school_id', $user->school_id)->where('sms_enabled', true)->exists();
        if (($data['sms_enabled'] ?? false) && (! config('communication.sms.enabled') || ! $schoolSmsEnabled)) {
            throw ValidationException::withMessages(['sms_enabled' => 'SMS is disabled by the platform.']);
        }
        $values = collect($data)->only(['email_enabled', 'sms_enabled', 'in_app_enabled', 'digest_frequency', 'quiet_hours_start', 'quiet_hours_end', 'timezone', 'language', 'emergency_override', 'marketing_opt_out'])->all();
        $values['updated_at'] = now();
        DB::table('communication_preferences')->where('id', $preference->id)->update($values);
        DB::table('communication_audit_logs')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'communication_id' => null, 'actor_user_id' => $user->id, 'action' => 'preferences_changed', 'entity_type' => 'communication_preference', 'entity_id' => $preference->id, 'metadata' => json_encode(['fields' => array_keys($values)]), 'created_at' => now()]);

        return $this->get($user);
    }
}
