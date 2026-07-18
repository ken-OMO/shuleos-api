<?php

namespace App\Services\Communication;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommunicationBrandingService
{
    public function get(string $schoolId): object
    {
        DB::table('communication_branding')->insertOrIgnore(['id' => (string) Str::uuid(), 'school_id' => $schoolId, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('communication_branding')->where('school_id', $schoolId)->first();
    }

    public function update(User $user, array $data): object
    {
        foreach (['sender_display_name', 'footer_text', 'address'] as $field) {
            if (isset($data[$field]) && ($data[$field] !== strip_tags($data[$field]) || preg_match('/script|iframe|form|tracking/i', $data[$field]))) {
                throw ValidationException::withMessages([$field => 'Branding must contain safe plain text.']);
            }
        }
        if (isset($data['reply_to_email']) && ! filter_var($data['reply_to_email'], FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['reply_to_email' => 'Reply-To must be a valid email address.']);
        }
        if (isset($data['sender_display_name']) && preg_match('/\b(shuleos support|shuleos billing|shuleos security)\b/i', $data['sender_display_name'])) {
            throw ValidationException::withMessages(['sender_display_name' => 'This sender display name is reserved.']);
        }
        $branding = $this->get($user->school_id);
        DB::table('communication_branding')->where('id', $branding->id)->update(collect($data)->only(['sender_display_name', 'reply_to_email', 'logo_reference', 'footer_text', 'address', 'phone', 'website', 'primary_color', 'secondary_color'])->all() + ['updated_by' => $user->id, 'updated_at' => now()]);
        DB::table('communication_audit_logs')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'communication_id' => null, 'actor_user_id' => $user->id, 'action' => 'branding_changed', 'entity_type' => 'communication_branding', 'entity_id' => $branding->id, 'metadata' => null, 'created_at' => now()]);

        return $this->get($user->school_id);
    }

    public function emailEnvelope(string $schoolId): array
    {
        $branding = $this->get($schoolId);
        $schoolName = DB::table('schools')->where('id', $schoolId)->value('school_name') ?: config('communication.email.from_name');
        $display = trim($branding->sender_display_name ?: $schoolName).' via ShuleOS';
        $replyTo = filter_var($branding->reply_to_email, FILTER_VALIDATE_EMAIL) ? $branding->reply_to_email : null;

        return ['from' => $display.' <'.config('communication.email.from_address').'>', 'reply_to' => $replyTo];
    }
}
