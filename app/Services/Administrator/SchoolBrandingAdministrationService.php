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

class SchoolBrandingAdministrationService
{
    private const TYPES = ['logo', 'report_card_logo', 'stamp', 'principal_signature', 'favicon', 'letterhead_background'];

    public function __construct(
        private AdministratorPortalAccessService $access,
        private AdministratorAuditService $audit,
        private FileSecurityManager $security,
        private FileQuarantine $quarantine,
        private SecureFileStorage $storage,
    ) {}

    public function index(User $user): array
    {
        $schoolId = $this->access->require($user, 'manage_school_branding')['school_id'];

        return [
            'assets' => DB::table('administrator_branding_assets')->where('school_id', $schoolId)->whereNull('archived_at')->select($this->safeColumns())->orderBy('asset_type')->get(),
            'allowed_types' => self::TYPES,
            'delivery' => 'controlled_endpoint_required',
        ];
    }

    public function settings(User $user, array $data): array
    {
        $this->access->require($user, 'manage_school_branding');
        $allowed = collect($data)->only(['primary_color', 'secondary_color', 'report_card_footer', 'letterhead_text'])->all();
        foreach (['primary_color', 'secondary_color'] as $field) {
            if (isset($allowed[$field]) && ! preg_match('/^#[0-9A-Fa-f]{6}$/', $allowed[$field])) {
                throw ValidationException::withMessages([$field => 'A six-digit hexadecimal color is required.']);
            }
        }
        $settings = DB::table('school_settings')->where('school_id', $user->school_id)->first();
        $columns = DB::getSchemaBuilder()->getColumnListing('school_settings');
        $updates = collect($allowed)->only($columns)->map(fn ($value) => is_string($value) ? strip_tags($value) : $value)->all();
        if ($settings && $updates) {
            DB::table('school_settings')->where('school_id', $user->school_id)->update($updates + ['updated_at' => now()]);
        }
        $this->audit->record($user, 'administrator_branding_settings_updated', 'school_settings', $settings?->id, [], array_keys($updates));

        return $this->index($user);
    }

    public function upload(User $user, UploadedFile $file, string $type): array
    {
        $schoolId = $this->access->require($user, 'manage_school_branding')['school_id'];
        abort_unless(in_array($type, self::TYPES, true), 422, 'Unsupported branding asset type.');
        $policy = new FilePolicy('Administrator Branding Image', ['jpg', 'jpeg', 'png', 'webp'], ['image/jpeg', 'image/png', 'image/webp'], 4 * 1024 * 1024, null, null, false, true, true, true, true, true, true, true, false, false);
        if ($this->security->scan($file, $policy)->failed()) {
            throw ValidationException::withMessages(['file' => 'The image failed the secure file validation pipeline.']);
        }
        $dimensions = @getimagesize($file->getRealPath());
        if (! $dimensions || $dimensions[0] > 4000 || $dimensions[1] > 4000) {
            throw ValidationException::withMessages(['file' => 'Image dimensions are invalid or exceed 4000 by 4000 pixels.']);
        }
        $quarantineId = $this->quarantine->quarantine($file);
        $stored = $this->storage->storeFromQuarantine($this->quarantine, $quarantineId);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $schoolId, $user, $type, $file, $stored) {
            DB::table('administrator_branding_assets')->where('school_id', $schoolId)->where('asset_type', $type)->whereNull('archived_at')->update(['status' => 'archived', 'archived_at' => now(), 'updated_at' => now()]);
            DB::table('administrator_branding_assets')->insert([
                'id' => $id, 'school_id' => $schoolId, 'asset_type' => $type,
                'original_filename' => basename($file->getClientOriginalName()), 'mime_type' => $file->getMimeType(),
                'size' => $stored['size'], 'source_hash' => $stored['source_hash'], 'stored_hash' => $stored['stored_hash'],
                'storage_id' => $stored['storage_id'], 'status' => 'approved', 'version' => 1, 'uploaded_by' => $user->id,
                'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $this->audit->record($user, 'administrator_branding_asset_uploaded', 'administrator_branding_assets', $id, [], ['asset_type' => $type, 'mime_type' => $file->getMimeType(), 'size' => $stored['size']]);

        return (array) DB::table('administrator_branding_assets')->where('id', $id)->select($this->safeColumns())->first();
    }

    public function archive(User $user, string $id): array
    {
        $schoolId = $this->access->require($user, 'manage_school_branding')['school_id'];
        $asset = DB::table('administrator_branding_assets')->where('id', $id)->where('school_id', $schoolId)->whereNull('archived_at')->firstOrFail();
        DB::table('administrator_branding_assets')->where('id', $id)->update(['status' => 'archived', 'archived_at' => now(), 'updated_at' => now()]);
        $this->audit->record($user, 'administrator_branding_asset_archived', 'administrator_branding_assets', $id, [], ['asset_type' => $asset->asset_type]);

        return ['id' => $id, 'archived' => true];
    }

    private function safeColumns(): array
    {
        return ['id', 'asset_type', 'original_filename', 'mime_type', 'size', 'status', 'version', 'uploaded_by', 'approved_at', 'archived_at', 'created_at', 'updated_at'];
    }
}
