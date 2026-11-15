<?php

declare(strict_types=1);

namespace App\Services\StudentElection;

use App\Models\StudentElection;
use App\Models\User;
use App\Services\Auth\AuthContextService;
use App\Services\StudentElectionSubscription\StudentElectionCommercialAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentElectionApplicationService
{
    /** @phpstan-impure */
    private function currentTime(): Carbon
    {
        return now();
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function actor(User $actor, ?string $permission = null, bool $historical = false): User
    {
        $actor = User::withoutGlobalScopes()->where('school_id', $actor->school_id)->whereKey($actor->id)->sharedLock()->firstOrFail();
        $auth = app(AuthContextService::class);
        $auth->assertAccessible($actor);
        abort_unless($actor->getAttribute('school_id') !== null, 403);
        if ($permission !== null) {
            abort_unless($auth->hasPermission($actor, $permission), 403);
        }
        $school = DB::table('schools')->where('id', $actor->school_id)->sharedLock()->firstOrFail();
        abort_if(in_array($school->lifecycle_state, ['suspended', 'locked', 'archived'], true) || (! $historical && $school->lifecycle_state === 'read_only'), 403);

        return $actor;
    }

    private function election(User $actor, string $id): StudentElection
    {
        abort_unless($actor->getAttribute('school_id') !== null, 403);

        return StudentElection::current()->where('school_id', $actor->school_id)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function time(string $value): string
    {
        if (! preg_match('/T.*(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            $this->fail('deadlines', 'Explicit ISO-8601 timezone offsets are required.');
        }
        try {
            return CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:sP');
        } catch (\Exception) {
            $this->fail('deadlines', 'Invalid deadline.');
        }
    }

    private function policy(StudentElection $e): \stdClass
    {
        if ($e->application_method !== 'versioned_v1' || ! $e->application_policy_id) {
            $this->fail('policy', 'A published versioned application policy is required.');
        }

        return DB::table('student_election_application_policies')->where('school_id', $e->school_id)
            ->where('election_id', $e->id)->where('id', $e->application_policy_id)->firstOrFail();
    }

    private function beforeOpen(StudentElection $e): void
    {
        if (! in_array($e->status, ['draft', 'nominations_open', 'nominations_closed', 'campaigning'], true)
            || DB::table('student_election_electorates')->where('election_id', $e->id)->exists()) {
            $this->fail('election', 'Application changes are frozen after first voting opens.');
        }
        $this->assertNotHeld($e);
    }

    public function assertNotHeld(StudentElection $e): void
    {
        if ($e->integrity_hold) {
            $this->fail('election', 'Election integrity review is required. Voting and declaration are paused.');
        }
        app(StudentElectionCommercialAccess::class)->available($e);
    }

    private function audit(StudentElection $e, User $actor, string $action, string $id): void
    {
        if (! in_array($action, ['application_withdrawn', 'application_consent_withdraw'], true)) {
            app(StudentElectionCommercialAccess::class)->normal($e->school_id);
        }
        if (! DB::table('student_election_audit_logs')->insert(['id' => (string) Str::uuid(), 'school_id' => $e->school_id,
            'election_id' => $e->id, 'actor_user_id' => $actor->id, 'action' => $action, 'entity_type' => 'application',
            'entity_id' => $id, 'metadata' => '{}', 'created_at' => now()])) {
            throw new \RuntimeException('Application audit failed.');
        }
    }

    private function event(object $a, User $actor, string $kind, string $reason, ?string $decision = null, ?string $designation = null): object
    {
        $row = ['id' => (string) Str::uuid(), 'application_id' => $a->id, 'version_id' => $a->version_id,
            'sequence' => DB::table('student_election_application_events')->where('application_id', $a->id)->count() + 1,
            'kind' => $kind, 'reason' => $reason, 'decision' => $decision, 'designation_id' => $designation,
            'actor_user_id' => $actor->id, 'created_at' => now()];
        if (! DB::table('student_election_application_events')->insert($row)) {
            throw new \RuntimeException('Application evidence failed.');
        }

        return (object) $row;
    }

    private function application(StudentElection $e, string $id, string $version): \stdClass
    {
        $a = DB::table('student_election_applications')->where('school_id', $e->school_id)->where('election_id', $e->id)->where('id', $id)->lockForUpdate()->firstOrFail();
        abort_unless($a->version_id === $version, 409, 'Application version changed.');

        return $a;
    }

    private function candidate(object $a, string $status): void
    {
        DB::table('student_election_candidates')->where('id', $a->candidate_id)->where('school_id', $a->school_id)
            ->update(['status' => $status, 'reviewed_by' => null, 'reviewed_at' => null, 'review_comments' => null]);
    }

    private function summary(object $a): array
    {
        return ['id' => $a->id, 'version' => $a->version_id, 'status' => $a->status, 'candidate_id' => $a->candidate_id];
    }

    public function publishPolicy(User $actor, string $election, ?string $expectedPolicy, array $data): array
    {
        return DB::transaction(function () use ($actor, $election, $expectedPolicy, $data): array {
            $e = $this->election($actor, $election);
            $actor = $this->actor($actor, 'manage_student_elections');
            $this->beforeOpen($e);
            abort_unless($e->application_policy_id === $expectedPolicy, 409, 'Policy changed.');
            if ($e->status !== 'draft' || ($expectedPolicy && $this->currentTime()->gte(CarbonImmutable::parse($this->policy($e)->opens_at)))) {
                $this->fail('policy', 'Policy and officers must be published before applications open.');
            }
            if (! $expectedPolicy && DB::table('student_election_candidates')->whereIn('election_position_id', $e->electionPositions()->pluck('id'))->exists()) {
                $this->fail('policy', 'Existing candidacies require a separately reviewed adoption; no automatic conversion.');
            }
            $v = validator($data, ['terms' => 'required|string|min:20|max:20000', 'photo_required' => 'required|boolean',
                'reviewer_id' => 'required|uuid', 'appeal_reviewer_id' => 'required|uuid|different:reviewer_id',
                'opens_at' => 'required|string', 'applications_close_at' => 'required|string', 'consent_close_at' => 'required|string',
                'reviews_close_at' => 'required|string', 'appeals_close_at' => 'required|string'])->validate();
            foreach (['reviewer_id', 'appeal_reviewer_id'] as $key) {
                $u = User::withoutGlobalScopes()->where('school_id', $e->school_id)->findOrFail($v[$key]);
                $this->actor($u, 'manage_election_candidates');
            }
            $previous = now()->utc();
            foreach (['opens_at', 'applications_close_at', 'consent_close_at', 'reviews_close_at', 'appeals_close_at'] as $key) {
                $v[$key] = $this->time($v[$key]);
                $at = CarbonImmutable::parse($v[$key]);
                if ($at->lte($previous) || $at->gte($e->voting_opens_at)) {
                    $this->fail('deadlines', 'Deadlines must increase strictly from now and all precede voting.');
                }
                $previous = $at;
            }
            $id = (string) Str::uuid();
            $revision = DB::table('student_election_application_policies')->where('election_id', $e->id)->count() + 1;
            DB::table('student_election_application_policies')->insert($v + ['id' => $id, 'school_id' => $e->school_id,
                'election_id' => $e->id, 'revision' => $revision, 'terms_digest' => hash('sha256', $v['terms']), 'created_by' => $actor->id]);
            $e->update(['application_method' => 'versioned_v1', 'application_policy_id' => $id]);
            $this->audit($e, $actor, 'application_policy_published', $id);

            return ['version' => $id, 'terms_digest' => hash('sha256', $v['terms']), 'revision' => $revision];
        });
    }

    private function position(StudentElection $e, object $learner, string $id): object
    {
        $ep = DB::table('student_election_positions')->where('school_id', $e->school_id)->where('election_id', $e->id)->where('id', $id)->where('active', true)->sharedLock()->firstOrFail();
        $p = DB::table('student_leadership_positions')->where('school_id', $e->school_id)->where('id', $ep->position_id)->where('active', true)->where('is_deleted', false)->sharedLock()->firstOrFail();
        if ($p->minimum_grade_id || $p->maximum_grade_id || ! in_array($p->scope, ['school', 'grade', 'stream'], true)
            || ($p->scope === 'grade' && ! $p->grade_id) || ($p->scope === 'stream' && ! $p->stream_id)
            || ($p->grade_id && $p->grade_id !== $learner->grade_id) || ($p->stream_id && $p->stream_id !== $learner->stream_id)
            || ($p->gender_requirement && strtolower($p->gender_requirement) !== strtolower((string) $learner->gender))
            || ! in_array($ep->candidate_eligibility_rules, [null, '[]', '{}'], true)
            || (int) ($ep->number_of_winners ?? $p->number_of_winners) !== 1) {
            $this->fail('position', 'Position eligibility cannot be established for this learner.');
        }
        if (! DB::table('academic_years')->where('id', $e->academic_year_id)->where('school_id', $e->school_id)->where('active', true)->exists()
            || ! DB::table('grades')->where('id', $learner->grade_id)->where('school_id', $e->school_id)->where('active', true)->exists()
            || ($learner->stream_id && ! DB::table('streams')->where('id', $learner->stream_id)->where('school_id', $e->school_id)->where('grade_id', $learner->grade_id)->where('active', true)->exists())) {
            $this->fail('position', 'Active school academic and learner scope is required.');
        }
        $p->election_position_id = $ep->id;

        return $p;
    }

    public function submit(User $actor, string $election, ?string $expectedVersion, array $data): array
    {
        return DB::transaction(function () use ($actor, $election, $expectedVersion, $data): array {
            $e = $this->election($actor, $election);
            $actor = $this->actor($actor);
            $this->beforeOpen($e);
            $policy = $this->policy($e);
            if ($e->status !== 'nominations_open' || $this->currentTime()->lt(CarbonImmutable::parse($policy->opens_at)) || $this->currentTime()->gte(CarbonImmutable::parse($policy->applications_close_at))) {
                $this->fail('window', 'Applications are not open.');
            }
            $v = validator($data, ['election_position_id' => 'required|uuid', 'manifesto' => 'required|string|min:20|max:10000',
                'slogan' => 'required|string|max:160', 'policy_version' => 'required|uuid', 'accept_terms' => 'required|accepted',
                'photo_path' => 'prohibited', 'photo_version' => 'nullable|uuid'])->validate();
            abort_unless($v['policy_version'] === $policy->id, 409, 'Terms version changed.');
            $learner = app(StudentElectionLearnerAccess::class)->learner($actor);
            DB::table('learners')->where('id', $learner->id)->where('school_id', $e->school_id)->lockForUpdate()->firstOrFail();
            $learner = app(StudentElectionLearnerAccess::class)->learner($actor);
            $photo = ! empty($v['photo_version'])
                ? app(StudentElectionPhotoService::class)->requireVersion($e->school_id, $learner->id, $v['photo_version'], true) : null;
            if ($policy->photo_required && $photo === null) {
                $this->fail('photo', 'Select the verified current learner profile photo.');
            }
            $position = $this->position($e, $learner, $v['election_position_id']);
            $a = DB::table('student_election_applications')->where('election_id', $e->id)->where('learner_id', $learner->id)->lockForUpdate()->first();
            abort_unless($a?->version_id === $expectedVersion, 409, 'Application changed.');
            if ($a?->candidate_id) {
                $this->candidate($a, 'withdrawn');
            }
            if (! $a) {
                $a = (object) ['id' => (string) Str::uuid(), 'school_id' => $e->school_id, 'election_id' => $e->id,
                    'learner_id' => $learner->id, 'version_id' => null, 'candidate_id' => null, 'status' => 'pending_consent'];
                DB::table('student_election_applications')->insert((array) $a);
            }
            if ($this->currentTime()->lt(CarbonImmutable::parse($policy->opens_at)) || $this->currentTime()->gte(CarbonImmutable::parse($policy->applications_close_at))) {
                $this->fail('window', 'The application deadline has passed. Refresh before retrying.');
            }
            $version = (string) Str::uuid();
            $snapshot = ['id' => $position->id, 'name' => $position->name, 'duties' => $position->description,
                'grade_id' => $position->grade_id, 'stream_id' => $position->stream_id];
            $revision = DB::table('student_election_application_versions')->where('application_id', $a->id)->count() + 1;
            DB::table('student_election_application_versions')->insert(['id' => $version, 'application_id' => $a->id, 'policy_id' => $policy->id,
                'election_position_id' => $position->election_position_id, 'revision' => $revision,
                'photo_version_id' => $photo?->id, 'position_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'manifesto' => $v['manifesto'], 'slogan' => $v['slogan'],
                'digest' => hash('sha256', json_encode($photo === null ? [$policy->id, $snapshot, $v['manifesto'], $v['slogan']] : [$policy->id, $snapshot, $v['manifesto'], $v['slogan'], $photo->id, $photo->source_hash], JSON_THROW_ON_ERROR)), 'created_by' => $actor->id]);
            $candidate = DB::table('student_election_candidates')->where('school_id', $e->school_id)->where('election_position_id', $position->election_position_id)->where('learner_id', $learner->id)->first();
            $candidateId = $candidate->id ?? (string) Str::uuid();
            $fields = ['photo_version_id' => $photo?->id, 'manifesto' => $v['manifesto'], 'slogan' => $v['slogan'], 'photo_path' => null, 'status' => 'pending_review',
                'is_deleted' => false, 'reviewed_by' => null, 'reviewed_at' => null, 'review_comments' => null];
            if ($candidate) {
                DB::table('student_election_candidates')->where('id', $candidateId)->update($fields);
            } else {
                DB::table('student_election_candidates')->insert($fields + ['id' => $candidateId, 'school_id' => $e->school_id,
                    'election_position_id' => $position->election_position_id, 'learner_id' => $learner->id, 'nomination_type' => 'learner_application', 'nominated_by_user_id' => $actor->id]);
            }
            DB::table('student_election_applications')->where('id', $a->id)->update(['version_id' => $version, 'candidate_id' => $candidateId, 'status' => 'pending_consent']);
            $this->audit($e, $actor, 'application_submitted', $version);

            return $this->summary((object) array_merge((array) $a, ['version_id' => $version, 'candidate_id' => $candidateId, 'status' => 'pending_consent']));
        });
    }

    private function assertPhoto(StudentElection $e, object $a, object $version, object $policy): void
    {
        if ($version->photo_version_id !== null) {
            app(StudentElectionPhotoService::class)->requireVersion($e->school_id, $a->learner_id, $version->photo_version_id);
        } elseif ($policy->photo_required) {
            $this->fail('photo', 'The submitted learner photo is required.');
        }
    }

    private function consentEvidence(object $a): object
    {
        $event = DB::table('student_election_application_events')->where('application_id', $a->id)->where('version_id', $a->version_id)
            ->where('kind', 'consent')->orderByDesc('sequence')->first();
        if (! $event || $event->decision !== 'accept') {
            $this->fail('consent', 'Current guardian acceptance is required.');
        }
        $guardian = User::withoutGlobalScopes()->findOrFail($event->actor_user_id);
        app(StudentElectionGuardianService::class)->guardianForConsent($guardian, $a->election_id, $a->learner_id, $event->designation_id);

        return $event;
    }

    public function consent(User $actor, string $election, string $application, string $version, string $designation, string $decision): array
    {
        return DB::transaction(/** @return array<string, mixed> */ function () use ($actor, $election, $application, $version, $designation, $decision): array {
            $e = $this->election($actor, $election);
            $actor = $this->actor($actor, null, $decision === 'withdraw');
            $policy = $this->policy($e);
            $a = $this->application($e, $application, $version);
            $this->assertPhoto($e, $a, DB::table('student_election_application_versions')->where('id', $version)->firstOrFail(), $policy);
            app(StudentElectionGuardianService::class)->guardianForConsent($actor, $e->id, $a->learner_id, $designation);
            if (! in_array($decision, ['accept', 'refuse', 'withdraw'], true)) {
                $this->fail('decision', 'Unsupported guardian decision.');
            }
            if ($decision !== 'withdraw') {
                $this->beforeOpen($e);
                if ($a->status === 'withdrawn' || $this->currentTime()->gte(CarbonImmutable::parse($policy->consent_close_at))) {
                    $this->fail('window', 'Guardian decision window is closed.');
                }
            }
            if ($decision !== 'withdraw' && DB::table('student_election_application_events')->where('version_id', $version)->where('kind', 'consent')->exists()) {
                $this->fail('consent', 'A fresh application version is required for a new consent decision.');
            }
            $this->event($a, $actor, 'consent', 'Guardian acted on the exact submitted application and terms version.', $decision, $designation);
            $this->invalidateGuardian($e, $a->learner_id);
            if ($decision === 'withdraw' && DB::table('student_election_electorates')->where('election_id', $e->id)->exists()) {
                $e->update(['integrity_hold' => true]);
                $this->event($a, $actor, 'incident', 'Post-opening consent withdrawal. Controlled integrity review required; ballots preserved.');
            }
            $status = $decision === 'accept' ? 'pending_review' : 'pending_consent';
            DB::table('student_election_applications')->where('id', $a->id)->update(['status' => $status]);
            $this->audit($e, $actor, 'application_consent_'.$decision, $a->version_id);

            return ['version' => $a->version_id, 'status' => $status, 'integrity_hold' => (bool) $e->integrity_hold];
        });
    }

    /** Called by guardian designation changes under the same election lock. */
    public function invalidateGuardian(StudentElection $e, string $learner): void
    {
        $a = DB::table('student_election_applications')->where('election_id', $e->id)->where('school_id', $e->school_id)->where('learner_id', $learner)->first();
        if ($a && $a->status !== 'withdrawn') {
            DB::table('student_election_applications')->where('id', $a->id)->update(['status' => 'pending_consent']);
            // Frozen ballots retain their original candidates after opening.
            if (! DB::table('student_election_electorates')->where('election_id', $e->id)->exists()) {
                $this->candidate($a, 'pending_review');
            }
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 2000) {
            $this->fail('reason', 'A reason of 10 to 2000 characters is required.');
        }

        return $reason;
    }

    public function appeal(User $actor, string $election, string $application, string $version, string $reason): array
    {
        return DB::transaction(function () use ($actor, $election, $application, $version, $reason): array {
            $e = $this->election($actor, $election);
            $actor = $this->actor($actor);
            $this->beforeOpen($e);
            $a = $this->application($e, $application, $version);
            abort_unless(app(StudentElectionLearnerAccess::class)->learner($actor)->id === $a->learner_id, 403);
            $p = $this->policy($e);
            if ($a->status !== 'rejected' || $this->currentTime()->gte(CarbonImmutable::parse($p->appeals_close_at))) {
                $this->fail('appeal', 'A current rejection and open appeal window are required.');
            }
            if (DB::table('student_election_application_events')->where('version_id', $version)->where('kind', 'appeal_request')->exists()) {
                $this->fail('appeal', 'Appeal already requested.');
            }
            $this->event($a, $actor, 'appeal_request', $this->reason($reason));
            $this->audit($e, $actor, 'application_appeal_requested', $version);

            return ['version' => $version, 'appeal_requested' => true];
        });
    }

    public function review(User $actor, string $election, string $application, string $version, bool $approve, string $reason, bool $appeal = false, bool $conflictFree = false): array
    {
        return DB::transaction(function () use ($actor, $election, $application, $version, $approve, $reason, $appeal, $conflictFree): array {
            $e = $this->election($actor, $election);
            $actor = $this->actor($actor, 'manage_election_candidates');
            $this->beforeOpen($e);
            $a = $this->application($e, $application, $version);
            $p = $this->policy($e);
            abort_unless($conflictFree && $actor->id === ($appeal ? $p->appeal_reviewer_id : $p->reviewer_id), 403);
            $learner = DB::table('learners')->where('school_id', $e->school_id)->where('id', $a->learner_id)->lockForUpdate()->firstOrFail();
            $conflict = DB::table('learner_parents as l')->join('parents as p', 'p.id', '=', 'l.parent_id')->where('l.learner_id', $learner->id)
                ->where('p.user_id', $actor->id)->where('p.school_id', $e->school_id)->exists();
            abort_if($conflict || $learner->user_id === $actor->id, 403, 'Reviewer must recuse.');
            if ($a->status === 'withdrawn' || $this->currentTime()->gte(CarbonImmutable::parse($appeal ? $p->appeals_close_at : $p->reviews_close_at))) {
                $this->fail('window', 'Review window is closed.');
            }
            if ($appeal && (! DB::table('student_election_application_events')->where('version_id', $version)->where('kind', 'appeal_request')->exists()
                || $a->status !== 'rejected')) {
                $this->fail('appeal', 'A rejected application and learner appeal are required.');
            }
            $kind = $appeal ? 'appeal' : 'review';
            if (DB::table('student_election_application_events')->where('version_id', $version)->where('kind', $kind)->exists()) {
                $this->fail('review', 'This version has already received that decision.');
            }
            // Even rejection is a board review of a guardian-consented application.
            $this->consentEvidence($a);
            $v = DB::table('student_election_application_versions')->where('id', $version)->firstOrFail();
            $this->assertPhoto($e, $a, $v, $p);
            $position = $this->position($e, $learner, $v->election_position_id);
            $snapshot = json_decode($v->position_snapshot, true, 512, JSON_THROW_ON_ERROR);
            if ($snapshot != ['id' => $position->id, 'name' => $position->name, 'duties' => $position->description, 'grade_id' => $position->grade_id, 'stream_id' => $position->stream_id]) {
                $this->fail('position', 'Position details changed; a new application version and consent are required.');
            }
            $candidate = DB::table('student_election_candidates')->where('school_id', $e->school_id)->where('id', $a->candidate_id)->lockForUpdate()->firstOrFail();
            if ($candidate->photo_version_id !== $v->photo_version_id) {
                $this->fail('photo', 'The candidate photo no longer matches the submitted application.');
            }
            $this->actor($actor, 'manage_election_candidates');
            if (! $learner->active || $learner->is_deleted || ! $learner->portal_enabled
                || $this->currentTime()->gte(CarbonImmutable::parse($appeal ? $p->appeals_close_at : $p->reviews_close_at))) {
                $this->fail('window', 'The review deadline has passed or learner eligibility changed. Refresh the application.');
            }
            $this->event($a, $actor, $kind, 'Reviewer attested no conflict. '.$this->reason($reason), $approve ? 'approve' : 'reject');
            $status = $approve ? 'approved' : 'rejected';
            DB::table('student_election_applications')->where('id', $a->id)->update(['status' => $status]);
            DB::table('student_election_candidates')->where('id', $a->candidate_id)->where('school_id', $e->school_id)
                ->update(['status' => $status, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_comments' => null]);
            $this->audit($e, $actor, 'application_'.$kind.'_'.$status, $version);

            return ['version' => $version, 'status' => $status];
        });
    }

    public function withdraw(User $actor, string $election, string $application, string $version, string $reason): array
    {
        return DB::transaction(function () use ($actor, $election, $application, $version, $reason): array {
            $e = $this->election($actor, $election);
            $actor = $this->actor($actor, null, true);
            $a = $this->application($e, $application, $version);
            abort_unless(app(StudentElectionLearnerAccess::class)->learner($actor)->id === $a->learner_id, 403);
            $frozen = DB::table('student_election_electorates')->where('election_id', $e->id)->exists();
            $this->event($a, $actor, $frozen ? 'incident' : 'withdraw', $this->reason($reason));
            if ($frozen) {
                $e->update(['integrity_hold' => true]);
            } else {
                $this->candidate($a, 'withdrawn');
            }
            DB::table('student_election_applications')->where('id', $a->id)->update(['status' => 'withdrawn']);
            $this->audit($e, $actor, 'application_withdrawn', $version);

            return ['version' => $version, 'status' => 'withdrawn', 'integrity_hold' => (bool) $e->integrity_hold];
        });
    }

    public function view(User $actor, string $election, string $application): array
    {
        return DB::transaction(function () use ($actor, $election, $application): array {
            $e = $this->election($actor, $election);
            $actor = $this->actor($actor, null, true);
            $a = DB::table('student_election_applications')->where('school_id', $e->school_id)->where('election_id', $e->id)->where('id', $application)->firstOrFail();
            $p = $this->policy($e);
            $own = DB::table('learners')->where('id', $a->learner_id)->where('school_id', $e->school_id)->where('user_id', $actor->id)->exists();
            if ($own) {
                abort_unless(app(StudentElectionLearnerAccess::class)->learner($actor)->id === $a->learner_id, 403);
            } elseif (in_array($actor->id, [$p->reviewer_id, $p->appeal_reviewer_id], true)) {
                $this->actor($actor, 'manage_election_candidates', true);
            } else {
                $d = DB::table('student_election_guardian_designations')->where('school_id', $e->school_id)->where('election_id', $e->id)->where('learner_id', $a->learner_id)->orderByDesc('revision')->first();
                abort_unless($d && $d->action === 'designate' && $d->parent_user_id === $actor->id, 403);
                app(StudentElectionGuardianService::class)->guardianForConsent($actor, $e->id, $a->learner_id, $d->id);
            }
            $v = DB::table('student_election_application_versions')->where('id', $a->version_id)->firstOrFail();
            $policy = DB::table('student_election_application_policies')->where('id', $v->policy_id)->firstOrFail();

            return $this->summary($a) + ['manifesto' => $v->manifesto, 'slogan' => $v->slogan,
                'position' => json_decode($v->position_snapshot, true, 512, JSON_THROW_ON_ERROR),
                'photo_version' => $v->photo_version_id, 'terms' => $policy->terms, 'policy_version' => $policy->id, 'terms_digest' => $policy->terms_digest];
        });
    }

    public function publishedPolicy(User $actor, string $election): array
    {
        return DB::transaction(/** @return array<string, mixed> */ function () use ($actor, $election): array {
            $e = $this->election($actor, $election);
            $this->actor($actor, null, true);
            $p = $this->policy($e);

            return ['policy_version' => $p->id, 'terms' => $p->terms, 'terms_digest' => $p->terms_digest,
                'photo_required' => (bool) $p->photo_required, 'opens_at' => $p->opens_at,
                'applications_close_at' => $p->applications_close_at, 'consent_close_at' => $p->consent_close_at,
                'reviews_close_at' => $p->reviews_close_at, 'appeals_close_at' => $p->appeals_close_at];
        });
    }

    public function assertReady(StudentElection $e): void
    {
        $this->assertNotHeld($e);
        if ($e->application_method !== 'versioned_v1') {
            return; // Historical elections are not silently reinterpreted.
        }
        $p = $this->policy($e);
        if ($this->currentTime()->lt(CarbonImmutable::parse($p->appeals_close_at))) {
            $this->fail('window', 'The appeal deadline must pass before voting opens.');
        }
        foreach ($e->electionPositions()->where('active', true)->pluck('id') as $ep) {
            if (! DB::table('student_election_candidates')->where('school_id', $e->school_id)->where('election_position_id', $ep)->where('status', 'approved')->where('is_deleted', false)->exists()) {
                $this->fail('application', 'Each active office requires an approved candidate before voting.');
            }
        }
        foreach (DB::table('student_election_applications')->where('election_id', $e->id)->get() as $a) {
            if (DB::table('student_election_application_events')->where('version_id', $a->version_id)->where('kind', 'appeal_request')->exists()
                && ! DB::table('student_election_application_events')->where('version_id', $a->version_id)->where('kind', 'appeal')->exists()) {
                $this->fail('appeal', 'An application appeal remains unresolved.');
            }
        }
        foreach (DB::table('student_election_candidates')->where('school_id', $e->school_id)->whereIn('election_position_id', $e->electionPositions()->pluck('id'))->where('status', 'approved')->where('is_deleted', false)->get() as $candidate) {
            $a = DB::table('student_election_applications')->where('election_id', $e->id)->where('school_id', $e->school_id)->where('candidate_id', $candidate->id)->where('status', 'approved')->first();
            if (! $a) {
                $this->fail('application', 'Approved candidate lacks current application evidence.');
            }
            $this->consentEvidence($a);
            $v = DB::table('student_election_application_versions')->where('id', $a->version_id)->firstOrFail();
            $this->assertPhoto($e, $a, $v, $p);
            $l = DB::table('learners')->where('school_id', $e->school_id)->where('id', $a->learner_id)->where('active', true)->where('is_deleted', false)->firstOrFail();
            $position = $this->position($e, $l, $v->election_position_id);
            if ($v->policy_id !== $p->id || $candidate->election_position_id !== $v->election_position_id || $candidate->learner_id !== $a->learner_id
                || $candidate->photo_version_id !== $v->photo_version_id || $candidate->manifesto !== $v->manifesto || $candidate->slogan !== $v->slogan || $candidate->photo_path !== null
                || json_decode($v->position_snapshot, true, 512, JSON_THROW_ON_ERROR) != ['id' => $position->id, 'name' => $position->name, 'duties' => $position->description, 'grade_id' => $position->grade_id, 'stream_id' => $position->stream_id]) {
                $this->fail('application', 'Candidate or position no longer matches the reviewed version.');
            }
            $decision = DB::table('student_election_application_events')->where('version_id', $a->version_id)->whereIn('kind', ['review', 'appeal'])
                ->orderByRaw("CASE WHEN kind = 'appeal' THEN 0 ELSE 1 END")->first();
            if (! $decision || $decision->decision !== 'approve' || $decision->actor_user_id !== ($decision->kind === 'appeal' ? $p->appeal_reviewer_id : $p->reviewer_id)) {
                $this->fail('application', 'Approved candidate lacks a current board decision.');
            }
        }
    }
}
