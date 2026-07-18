<?php

namespace App\Services\Communication;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommunicationDigestService
{
    private const TYPES = ['parent_daily', 'learner_homework', 'teacher_morning', 'leadership_daily', 'weekly_attendance', 'finance_reminder'];

    public function generate(): int
    {
        $count = 0;
        DB::table('communication_preferences as preference')
            ->join('users as user', 'user.id', '=', 'preference.user_id')
            ->join('schools as school', 'school.id', '=', 'preference.school_id')
            ->where('user.active', true)
            ->where('user.is_deleted', false)
            ->where('school.active', true)
            ->where('school.is_deleted', false)
            ->whereIn('preference.digest_frequency', ['daily', 'weekly'])
            ->orderBy('preference.user_id')
            ->limit(config('communication.scheduler_batch_size', 100))
            ->select('preference.*')
            ->get()
            ->each(function ($preference) use (&$count) {
                $type = $preference->digest_frequency === 'weekly' ? 'weekly_attendance' : 'parent_daily';
                abort_unless(in_array($type, self::TYPES, true), 422);
                $safeSummary = ['type' => $type, 'date' => today()->toDateString(), 'user_id' => $preference->user_id];
                $inserted = DB::table('communication_digest_runs')->insertOrIgnore(['id' => (string) Str::uuid(), 'school_id' => $preference->school_id, 'user_id' => $preference->user_id, 'digest_type' => $type, 'digest_date' => today(), 'content_hash' => hash('sha256', json_encode($safeSummary)), 'communication_id' => null, 'status' => 'generated', 'created_at' => now()]);
                $count += $inserted ? 1 : 0;
            });

        return $count;
    }
}
