<?php

namespace App\Services\ParentPortal;

use App\Models\User;
use App\Services\Communication\CommunicationPreferenceService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ParentProfilePreferenceService
{
    public function __construct(private ParentPortalAccessService $access, private CommunicationPreferenceService $preferences, private ParentPortalAuditService $audit) {}

    public function profile(User $user): array
    {
        $parent = $this->access->parent($user);

        return [
            'id' => $parent->id,
            'first_name' => $user->first_name ?: $parent->first_name,
            'last_name' => $user->last_name ?: $parent->last_name,
            'display_name' => trim(($user->first_name ?: $parent->first_name).' '.($user->last_name ?: $parent->last_name)),
            'email_masked' => $this->maskEmail($user->email ?: $parent->email),
            'phone_masked' => $this->maskPhone($user->phone ?: $parent->phone),
            'pending_contact_changes' => DB::table('parent_contact_change_requests')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('status', 'pending')->select('id', 'contact_type', 'status', 'created_at')->latest()->get(),
        ];
    }

    public function updateProfile(User $user, array $data): array
    {
        $this->access->parent($user);
        DB::transaction(function () use ($user, $data) {
            $names = collect($data)->only(['first_name', 'last_name'])->map(fn ($value) => trim(strip_tags((string) $value)))->all();
            if ($names) {
                DB::table('users')->where('id', $user->id)->where('school_id', $user->school_id)->update($names + ['updated_at' => now()]);
            }
            foreach (['email', 'phone'] as $type) {
                if (! array_key_exists($type, $data)) {
                    continue;
                }
                $value = trim($data[$type]);
                DB::table('parent_contact_change_requests')->insert([
                    'id' => (string) Str::uuid(),
                    'school_id' => $user->school_id,
                    'user_id' => $user->id,
                    'contact_type' => $type,
                    'requested_value_encrypted' => Crypt::encryptString($value),
                    'requested_value_hash' => hash('sha256', strtolower($value)),
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->audit->record($user, 'profile_updated', null, 'user', $user->id, ['name_updated' => (bool) $names, 'contact_change_requested' => isset($data['email']) || isset($data['phone'])]);
        });

        return $this->profile($user->fresh());
    }

    public function preferences(User $user): object
    {
        $this->access->parent($user);

        return $this->preferences->get($user);
    }

    public function updatePreferences(User $user, array $data): object
    {
        $this->access->parent($user);
        $preference = $this->preferences->update($user, $data);
        $this->audit->record($user, 'preferences_updated', null, 'communication_preference', $preference->id);

        return $preference;
    }

    private function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).str_repeat('*', max(2, mb_strlen($local) - 1)).'@'.$domain;
    }

    private function maskPhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        return str_repeat('*', max(0, mb_strlen($phone) - 4)).mb_substr($phone, -4);
    }
}
