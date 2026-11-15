<?php

declare(strict_types=1);

namespace App\Services\StudentElection;

use App\Models\StudentElection;
use App\Models\User;
use App\Services\Auth\AuthContextService;
use Illuminate\Support\Facades\DB;

class StudentElectionReconciliationService
{
    public function __construct(private readonly AuthContextService $access) {}

    public function inspect(User $actor, string $election): array
    {
        $this->access->assertAccessible($actor);
        abort_unless($actor->getAttribute('school_id') !== null
            && $this->access->hasPermission($actor, 'manage_student_elections')
            && $this->access->hasPermission($actor, 'tally_student_elections'), 403);

        return DB::transaction(function () use ($actor, $election) {
            $record = StudentElection::current()->whereKey($election)->where('school_id', $actor->school_id)
                ->whereIn('status', ['voting_closed', 'tallied', 'published'])->sharedLock()->firstOrFail();
            // One SQL statement gives every aggregate the same MVCC snapshot.
            // No learner IDs, individual ballot IDs, choices or timestamps leave it.
            $sql = <<<'SQL'
WITH parameters AS (SELECT ?::uuid AS election, ?::uuid AS school),
positions AS (
    SELECT ep.id, ep.school_id, ep.election_id, p.school_id AS position_school,
           e.ballot_method, (SELECT COUNT(*) FROM student_election_candidates c WHERE c.election_position_id = ep.id AND c.status = 'approved' AND NOT c.is_deleted) AS nominees,
           COALESCE(ep.number_of_winners, p.number_of_winners) AS seats
    FROM student_election_positions ep
    JOIN student_elections e ON e.id = ep.election_id
    LEFT JOIN student_leadership_positions p ON p.id = ep.position_id
    WHERE ep.election_id = (SELECT election FROM parameters)
),
registry AS (
    SELECT v.* FROM student_election_voters v
    WHERE v.election_id = (SELECT election FROM parameters)
       OR v.election_position_id IN (SELECT id FROM positions)
),
ballots AS (
    SELECT b.*, COALESCE(
        b.school_id = (SELECT school FROM parameters)
        AND b.election_id = (SELECT election FROM parameters)
        AND b.ballot_status = 'valid'
        AND ((b.choice = 'selection' AND c.school_id = (SELECT school FROM parameters)
            AND c.election_position_id = b.election_position_id AND c.status = 'approved' AND NOT c.is_deleted
            AND l.school_id = (SELECT school FROM parameters)
            AND (p.ballot_method = 'legacy_single_selection' OR p.nominees > 1))
          OR (p.ballot_method = 'classroom_single_seat_v1' AND b.candidate_id IS NULL
            AND (b.choice = 'abstain' OR (p.nominees = 1 AND b.choice IN ('approve','reject'))))), false) AS structurally_valid
    FROM student_election_ballots b
    LEFT JOIN positions p ON p.id = b.election_position_id
    LEFT JOIN student_election_candidates c ON c.id = b.candidate_id
    LEFT JOIN learners l ON l.id = c.learner_id
    WHERE b.election_id = (SELECT election FROM parameters)
       OR b.election_position_id IN (SELECT id FROM positions)
),
totals AS (
    SELECT p.id AS election_position_id, p.seats,
       COALESCE(p.school_id = (SELECT school FROM parameters)
            AND p.position_school = (SELECT school FROM parameters)
            AND p.ballot_method IN ('legacy_single_selection','classroom_single_seat_v1'), false) AS position_integrity_ok,
       (SELECT COUNT(*) FROM registry v WHERE v.election_position_id = p.id AND v.eligible) AS eligible_registry_rows,
       (SELECT COUNT(*) FROM registry v WHERE v.election_position_id = p.id AND v.has_voted) AS position_participations,
       (SELECT COUNT(*) FROM registry v WHERE v.election_position_id = p.id AND
            (v.school_id <> (SELECT school FROM parameters)
             OR v.election_id <> (SELECT election FROM parameters)
             OR (v.has_voted AND NOT v.eligible))) AS registry_integrity_errors,
       (SELECT COUNT(*) FROM ballots b WHERE b.election_position_id = p.id) AS ballot_rows,
       (SELECT COUNT(*) FROM ballots b WHERE b.election_position_id = p.id AND b.structurally_valid) AS valid_ballots,
       (SELECT COUNT(*) FROM ballots b WHERE b.election_position_id = p.id AND b.structurally_valid AND b.choice = 'selection') AS valid_selections,
       (SELECT COUNT(*) FROM ballots b WHERE b.election_position_id = p.id AND b.structurally_valid AND b.choice = 'abstain') AS abstentions,
       (SELECT COUNT(*) FROM ballots b WHERE b.election_position_id = p.id AND b.structurally_valid AND b.choice = 'approve') AS approvals,
       (SELECT COUNT(*) FROM ballots b WHERE b.election_position_id = p.id AND b.structurally_valid AND b.choice = 'reject') AS rejections,
       (SELECT COUNT(*) FROM ballots b WHERE b.election_position_id = p.id AND NOT b.structurally_valid) AS ballot_integrity_errors
    FROM positions p
)
SELECT jsonb_build_object(
    'positions', COALESCE((SELECT jsonb_agg(to_jsonb(t) ORDER BY t.election_position_id) FROM totals t), '[]'::jsonb),
    'unassigned_ballot_rows', (SELECT COUNT(*) FROM ballots b WHERE b.election_position_id NOT IN (SELECT id FROM positions)),
    'unassigned_registry_rows', (SELECT COUNT(*) FROM registry v WHERE v.election_position_id NOT IN (SELECT id FROM positions)),
    'learners_with_any_position_participation', (SELECT COUNT(DISTINCT v.learner_id) FROM registry v
        WHERE v.school_id = (SELECT school FROM parameters) AND v.election_id = (SELECT election FROM parameters) AND v.has_voted)
) AS payload
SQL;
            $data = json_decode(DB::selectOne($sql, [$record->id, $actor->school_id])->payload, true, 512, JSON_THROW_ON_ERROR);
            $issues = [];
            if ($data['positions'] === []) {
                $issues[] = 'no_positions';
            }
            if ($data['unassigned_ballot_rows'] > 0 || $data['unassigned_registry_rows'] > 0) {
                $issues[] = 'unassigned_records';
            }
            foreach ($data['positions'] as &$position) {
                $position['participation_minus_ballots'] = $position['position_participations'] - $position['ballot_rows'];
                if (! $position['position_integrity_ok'] || $position['registry_integrity_errors'] > 0 || $position['ballot_integrity_errors'] > 0) {
                    $issues[] = 'record_integrity_error';
                }
                if ((int) $position['seats'] !== 1) {
                    $issues[] = 'unsupported_multiseat_policy';
                }
                if ($position['participation_minus_ballots'] !== 0 || $position['ballot_rows'] !== $position['valid_ballots']) {
                    $issues[] = 'count_mismatch';
                }
            }
            unset($position);

            return ['election_id' => $record->id, 'mode' => 'single_seat_envelopes',
                'reconciled' => $issues === [], 'issues' => array_values(array_unique($issues)), ...$data];
        });
    }
}
