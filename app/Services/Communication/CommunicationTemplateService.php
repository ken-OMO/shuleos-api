<?php

namespace App\Services\Communication;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommunicationTemplateService
{
    private const PLACEHOLDERS = ['school_name', 'recipient_name', 'learner_name', 'grade_name', 'stream_name', 'due_date', 'amount', 'currency', 'login_url'];

    public function __construct(private CommunicationAuditService $audit) {}

    public function save(User $user, array $data, ?string $id = null): object
    {
        $this->validateText($data['subject']);
        $this->validateText($data['body']);
        $values = ['school_id' => $user->school_id, 'name' => trim($data['name']), 'category' => $data['category'], 'subject' => $data['subject'], 'body' => $data['body'], 'updated_at' => now()];
        if ($id) {
            $template = DB::table('communication_templates')->where('id', $id)->where('school_id', $user->school_id)->where('active', true)->first();
            abort_unless($template, 404);
            abort_if($template->is_system, 409, 'System templates are read-only.');
            DB::table('communication_templates')->where('id', $id)->update($values + ['version' => $template->version + 1]);
            $action = 'template_edited';
        } else {
            $id = (string) Str::uuid();
            DB::table('communication_templates')->insert($values + ['id' => $id, 'version' => 1, 'is_system' => false, 'active' => true, 'created_by' => $user->id, 'created_at' => now()]);
            $action = 'template_created';
        }
        $this->audit->record($user, $action, 'communication_template', $id);

        return $this->find($user, $id);
    }

    public function render(User $user, string $id, array $values): array
    {
        $template = $this->find($user, $id);
        $unknown = array_diff(array_keys($values), self::PLACEHOLDERS);
        if ($unknown) {
            throw ValidationException::withMessages(['placeholders' => 'Unknown template placeholders: '.implode(', ', $unknown)]);
        }
        $replace = collect($values)->mapWithKeys(fn ($value, $key) => ['{{'.$key.'}}' => (string) $value])->all();

        return ['subject' => strtr($template->subject, $replace), 'body' => strtr($template->body, $replace), 'template_id' => $template->id, 'template_version' => $template->version];
    }

    public function archive(User $user, string $id): object
    {
        $template = $this->find($user, $id);
        abort_if($template->is_system, 409, 'System templates cannot be archived.');
        DB::table('communication_templates')->where('id', $id)->update(['active' => false, 'archived_at' => now(), 'updated_at' => now()]);
        $this->audit->record($user, 'template_archived', 'communication_template', $id);

        return $this->find($user, $id, false);
    }

    public function find(User $user, string $id, bool $active = true): object
    {
        $row = DB::table('communication_templates')->where('id', $id)->where(fn ($query) => $query->where('school_id', $user->school_id)->orWhereNull('school_id'))->when($active, fn ($query) => $query->where('active', true))->first();
        abort_unless($row, 404);

        return $row;
    }

    private function validateText(string $text): void
    {
        if ($text !== strip_tags($text) || preg_match('/<\?php|@php|\{!!|<script|javascript:/i', $text)) {
            throw ValidationException::withMessages(['template' => 'Templates must be plain text without executable markup.']);
        }
        preg_match_all('/{{\s*([a-zA-Z0-9_]+)\s*}}/', $text, $matches);
        $unknown = array_diff($matches[1], self::PLACEHOLDERS);
        if ($unknown) {
            throw ValidationException::withMessages(['template' => 'Unknown placeholders: '.implode(', ', array_unique($unknown))]);
        }
    }
}
