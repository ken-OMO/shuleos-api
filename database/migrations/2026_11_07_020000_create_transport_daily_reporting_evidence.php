<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $guarded = [
        'transport_dated_assignments', 'transport_learner_reservations',
        'transport_trips', 'transport_trip_roster', 'transport_journey_events',
        'transport_journey_outcomes', 'transport_trip_completions',
        'transport_exception_follow_ups', 'transport_handover_authorizations',
        'transport_handover_revocations', 'transport_reporting_obligations',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Daily reporting requires PostgreSQL.');
        }
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '60s'");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.transport_report_scope_lock() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    PERFORM 1 FROM schools WHERE id = NEW.school_id FOR UPDATE;
    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION public.transport_daily_report_snapshot(p_school uuid, p_obligation uuid)
RETURNS jsonb LANGUAGE plpgsql VOLATILE AS $$
DECLARE
    obligation_row transport_reporting_obligations%ROWTYPE;
    assignment_row transport_dated_assignments%ROWTYPE;
    trip_row transport_trips%ROWTYPE;
    attendance jsonb;
    runs jsonb := '[]'::jsonb;
    evidence jsonb;
    totals jsonb := '{"planned_runs":0,"cancelled_runs":0,"uncaptured_runs":0,"captured_runs":0,"completed_runs":0,"incomplete_runs":0,"expected_journey_legs":0,"transported_legs":0,"unique_learners_transported":0,"awaiting_outcome":0,"on_board":0,"handed_over":0,"missed_pickup":0,"not_boarded":0,"cancelled":0,"unresolved_exceptions":0}'::jsonb;
    key_name text;
    value_count integer;
    trip_ids uuid[] := ARRAY[]::uuid[];
    contributors uuid[] := ARRAY[]::uuid[];
    ids uuid[];
    completion_row transport_trip_completions%ROWTYPE;
BEGIN
    SELECT * INTO STRICT obligation_row FROM transport_reporting_obligations
      WHERE school_id = p_school AND id = p_obligation;
    FOR assignment_row IN SELECT * FROM transport_dated_assignments
      WHERE school_id = p_school AND schedule_id = obligation_row.schedule_id
        AND service_date = obligation_row.service_date ORDER BY id
    LOOP
        totals := jsonb_set(totals, '{planned_runs}', to_jsonb((totals->>'planned_runs')::int + 1));
        SELECT * INTO trip_row FROM transport_trips
          WHERE school_id = p_school AND assignment_id = assignment_row.id;
        evidence := jsonb_build_object('assignment', to_jsonb(assignment_row));
        IF trip_row.id IS NULL THEN
            IF assignment_row.active THEN
                key_name := 'uncaptured_runs';
                SELECT count(*)::int INTO value_count FROM transport_learner_reservations
                  WHERE school_id = p_school AND assignment_id = assignment_row.id AND active;
                totals := jsonb_set(totals, '{expected_journey_legs}', to_jsonb((totals->>'expected_journey_legs')::int + value_count));
                totals := jsonb_set(totals, '{awaiting_outcome}', to_jsonb((totals->>'awaiting_outcome')::int + value_count));
            ELSE
                key_name := 'cancelled_runs';
            END IF;
        ELSE
            key_name := 'captured_runs';
            trip_ids := array_append(trip_ids, trip_row.id);
            attendance := public.transport_trip_attendance_snapshot(p_school, trip_row.id);
            totals := jsonb_set(totals, '{expected_journey_legs}', to_jsonb((totals->>'expected_journey_legs')::int + (attendance->>'planned_legs')::int));
            FOREACH key_name IN ARRAY ARRAY['transported_legs','awaiting_outcome','on_board','handed_over','missed_pickup','not_boarded','cancelled','unresolved_exceptions'] LOOP
                totals := jsonb_set(totals, ARRAY[key_name], to_jsonb((totals->>key_name)::int + (attendance->>key_name)::int));
            END LOOP;
            SELECT * INTO completion_row FROM transport_trip_completions
              WHERE school_id = p_school AND trip_id = trip_row.id ORDER BY sequence DESC LIMIT 1;
            key_name := CASE WHEN completion_row.status = 'completed' THEN 'completed_runs' ELSE 'incomplete_runs' END;
            totals := jsonb_set(totals, ARRAY[key_name], to_jsonb((totals->>key_name)::int + 1));
            key_name := 'captured_runs';
            evidence := evidence || jsonb_build_object(
                'trip', to_jsonb(trip_row), 'attendance', attendance,
                'roster', COALESCE((SELECT jsonb_agg(to_jsonb(r) ORDER BY r.id) FROM transport_trip_roster r WHERE r.school_id=p_school AND r.trip_id=trip_row.id), '[]'::jsonb),
                'events', COALESCE((SELECT jsonb_agg(to_jsonb(e) ORDER BY e.roster_id,e.sequence) FROM transport_journey_events e WHERE e.school_id=p_school AND e.trip_id=trip_row.id), '[]'::jsonb),
                'outcomes', COALESCE((SELECT jsonb_agg(to_jsonb(o) ORDER BY o.id) FROM transport_journey_outcomes o WHERE o.school_id=p_school AND o.trip_id=trip_row.id), '[]'::jsonb),
                'follow_ups', COALESCE((SELECT jsonb_agg(to_jsonb(f) ORDER BY f.failure_event_id,f.sequence) FROM transport_exception_follow_ups f JOIN transport_trip_roster r ON r.school_id=f.school_id AND r.id=f.roster_id WHERE f.school_id=p_school AND r.trip_id=trip_row.id), '[]'::jsonb),
                'completions', COALESCE((SELECT jsonb_agg(to_jsonb(c) ORDER BY c.sequence) FROM transport_trip_completions c WHERE c.school_id=p_school AND c.trip_id=trip_row.id), '[]'::jsonb)
            );
        END IF;
        totals := jsonb_set(totals, ARRAY[key_name], to_jsonb((totals->>key_name)::int + 1));
        evidence := evidence || jsonb_build_object(
            'reservations', COALESCE((SELECT jsonb_agg(to_jsonb(r) ORDER BY r.id) FROM transport_learner_reservations r WHERE r.school_id=p_school AND r.assignment_id=assignment_row.id), '[]'::jsonb),
            'authorizations', COALESCE((SELECT jsonb_agg(to_jsonb(a) ORDER BY a.id) FROM transport_handover_authorizations a WHERE a.school_id=p_school AND a.assignment_id=assignment_row.id), '[]'::jsonb),
            'revocations', COALESCE((SELECT jsonb_agg(to_jsonb(v) ORDER BY v.id) FROM transport_handover_revocations v JOIN transport_handover_authorizations a ON a.school_id=v.school_id AND a.id=v.authorization_id WHERE a.school_id=p_school AND a.assignment_id=assignment_row.id), '[]'::jsonb)
        );
        IF assignment_row.active OR trip_row.id IS NOT NULL THEN
            contributors := contributors || ARRAY[assignment_row.driver_id, assignment_row.attendant_id];
        END IF;
        runs := runs || jsonb_build_array(evidence);
    END LOOP;
    SELECT count(DISTINCT learner_id)::int INTO value_count FROM (
        SELECT DISTINCT ON (roster_id) learner_id,state_after FROM transport_journey_events
        WHERE school_id=p_school AND trip_id=ANY(trip_ids) ORDER BY roster_id,sequence DESC
    ) latest WHERE state_after IN ('on_board','at_school','at_destination');
    totals := jsonb_set(totals, '{unique_learners_transported}', to_jsonb(value_count));
    SELECT array_agg(DISTINCT actor_id ORDER BY actor_id) INTO ids FROM (
        SELECT recorded_by AS actor_id FROM transport_journey_events WHERE school_id=p_school AND trip_id=ANY(trip_ids)
        UNION SELECT from_user_id FROM transport_journey_events WHERE school_id=p_school AND trip_id=ANY(trip_ids)
        UNION SELECT to_user_id FROM transport_journey_events WHERE school_id=p_school AND trip_id=ANY(trip_ids)
        UNION SELECT recorded_by FROM transport_journey_outcomes WHERE school_id=p_school AND trip_id=ANY(trip_ids)
        UNION SELECT recorded_by FROM transport_trip_completions WHERE school_id=p_school AND trip_id=ANY(trip_ids)
        UNION SELECT captured_by FROM transport_trips WHERE school_id=p_school AND id=ANY(trip_ids)
        UNION SELECT f.recorded_by FROM transport_exception_follow_ups f JOIN transport_trip_roster r ON r.school_id=f.school_id AND r.id=f.roster_id WHERE f.school_id=p_school AND r.trip_id=ANY(trip_ids)
        UNION SELECT unnest(contributors)
    ) actors WHERE actor_id IS NOT NULL;
    RETURN jsonb_build_object('obligation',to_jsonb(obligation_row),'totals',totals,'runs',runs,'contributors',to_jsonb(COALESCE(ids,ARRAY[]::uuid[])));
END;
$$;

CREATE TABLE transport_daily_submissions (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL,
    obligation_id uuid NOT NULL,
    revision integer NOT NULL CHECK (revision > 0),
    previous_id uuid,
    summary text NOT NULL CHECK (length(btrim(summary)) BETWEEN 10 AND 4000),
    exception_note text CHECK (exception_note IS NULL OR length(exception_note) <= 4000),
    snapshot jsonb NOT NULL,
    submitted_by uuid NOT NULL,
    submitted_at timestamptz NOT NULL,
    late boolean NOT NULL DEFAULT false,
    overdue boolean NOT NULL DEFAULT false,
    UNIQUE(school_id,id),
    UNIQUE(school_id,obligation_id,revision),
    FOREIGN KEY(school_id,obligation_id) REFERENCES transport_reporting_obligations(school_id,id),
    FOREIGN KEY(school_id,submitted_by) REFERENCES users(school_id,id),
    FOREIGN KEY(school_id,previous_id) REFERENCES transport_daily_submissions(school_id,id)
);
CREATE TABLE transport_daily_reviews (
    id uuid PRIMARY KEY,
    school_id uuid NOT NULL,
    submission_id uuid NOT NULL,
    decision text NOT NULL CHECK (decision IN ('accepted','changes_requested')),
    note text NOT NULL CHECK (length(btrim(note)) BETWEEN 10 AND 4000),
    reviewed_by uuid NOT NULL,
    reviewed_at timestamptz NOT NULL,
    UNIQUE(school_id,id),
    UNIQUE(school_id,submission_id),
    FOREIGN KEY(school_id,submission_id) REFERENCES transport_daily_submissions(school_id,id),
    FOREIGN KEY(school_id,reviewed_by) REFERENCES users(school_id,id)
);
CREATE OR REPLACE FUNCTION public.transport_daily_submission_validate() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    obligation_row transport_reporting_obligations%ROWTYPE;
    latest transport_daily_submissions%ROWTYPE;
    current_snapshot jsonb;
BEGIN
    PERFORM 1 FROM schools WHERE id=NEW.school_id FOR UPDATE;
    SELECT * INTO STRICT obligation_row FROM transport_reporting_obligations WHERE school_id=NEW.school_id AND id=NEW.obligation_id FOR UPDATE;
    SELECT * INTO latest FROM transport_daily_submissions WHERE school_id=NEW.school_id AND obligation_id=NEW.obligation_id ORDER BY revision DESC LIMIT 1;
    IF NEW.previous_id IS DISTINCT FROM latest.id THEN
        RAISE EXCEPTION 'Stale submission revision' USING ERRCODE='23P01';
    END IF;
    IF latest.id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM transport_daily_reviews WHERE school_id=NEW.school_id AND submission_id=latest.id AND decision='changes_requested'
    ) THEN
        RAISE EXCEPTION 'A new revision requires requested changes' USING ERRCODE='23514';
    END IF;
    current_snapshot := public.transport_daily_report_snapshot(NEW.school_id,NEW.obligation_id);
    IF NEW.snapshot IS DISTINCT FROM current_snapshot THEN
        RAISE EXCEPTION 'Stale report evidence' USING ERRCODE='23P01';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM transport_dated_assignments a LEFT JOIN transport_trips t ON t.school_id=a.school_id AND t.assignment_id=a.id
        WHERE a.school_id=NEW.school_id AND a.schedule_id=obligation_row.schedule_id AND a.service_date=obligation_row.service_date
        AND (a.active OR t.id IS NOT NULL) AND (a.driver_id=NEW.submitted_by OR a.attendant_id=NEW.submitted_by)
    ) THEN
        RAISE EXCEPTION 'Submitter lacks concrete responsibility' USING ERRCODE='23514';
    END IF;
    IF EXISTS (SELECT 1 FROM transport_dated_assignments a WHERE a.school_id=NEW.school_id AND a.schedule_id=obligation_row.schedule_id AND a.service_date=obligation_row.service_date AND a.active AND a.completes_at>NEW.submitted_at) THEN
        RAISE EXCEPTION 'Service window has not ended' USING ERRCODE='23514';
    END IF;
    IF ((current_snapshot->'totals'->>'uncaptured_runs')::int > 0
        OR (current_snapshot->'totals'->>'incomplete_runs')::int > 0
        OR (current_snapshot->'totals'->>'unresolved_exceptions')::int > 0)
        AND length(btrim(COALESCE(NEW.exception_note,''))) < 10 THEN
        RAISE EXCEPTION 'Open operational issues require an explicit account' USING ERRCODE='23514';
    END IF;
    IF latest.id IS NOT NULL AND NEW.submitted_at < latest.submitted_at THEN
        RAISE EXCEPTION 'Submission chronology cannot move backwards' USING ERRCODE='23514';
    END IF;
    NEW.revision := COALESCE(latest.revision,0)+1;
    NEW.late := NEW.submitted_at > obligation_row.deadline_at;
    NEW.overdue := NEW.submitted_at > obligation_row.grace_until;
    RETURN NEW;
END;
$$;
CREATE OR REPLACE FUNCTION public.transport_daily_review_validate() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE submission_row transport_daily_submissions%ROWTYPE;
BEGIN
    PERFORM 1 FROM schools WHERE id=NEW.school_id FOR UPDATE;
    SELECT * INTO STRICT submission_row FROM transport_daily_submissions WHERE school_id=NEW.school_id AND id=NEW.submission_id;
    PERFORM 1 FROM transport_reporting_obligations WHERE school_id=NEW.school_id AND id=submission_row.obligation_id FOR UPDATE;
    IF NEW.reviewed_at < submission_row.submitted_at THEN
        RAISE EXCEPTION 'Review cannot precede submission' USING ERRCODE='23514';
    END IF;
    IF EXISTS (SELECT 1 FROM transport_daily_submissions WHERE school_id=NEW.school_id AND obligation_id=submission_row.obligation_id AND revision>submission_row.revision) THEN
        RAISE EXCEPTION 'Cannot review an obsolete revision' USING ERRCODE='23P01';
    END IF;
    IF EXISTS (SELECT 1 FROM transport_daily_submissions WHERE school_id=NEW.school_id AND obligation_id=submission_row.obligation_id AND submitted_by=NEW.reviewed_by)
        OR EXISTS (SELECT 1 FROM transport_daily_submissions prior WHERE prior.school_id=NEW.school_id AND prior.obligation_id=submission_row.obligation_id AND (prior.snapshot->'contributors' ? NEW.reviewed_by::text)) THEN
        RAISE EXCEPTION 'Independent reviewer required' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE OR REPLACE FUNCTION public.transport_daily_evidence_immutable() RETURNS trigger
LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Submitted evidence is immutable' USING ERRCODE='23514'; END; $$;
CREATE TRIGGER transport_daily_submission_validate BEFORE INSERT ON transport_daily_submissions FOR EACH ROW EXECUTE FUNCTION public.transport_daily_submission_validate();
CREATE TRIGGER transport_daily_review_validate BEFORE INSERT ON transport_daily_reviews FOR EACH ROW EXECUTE FUNCTION public.transport_daily_review_validate();
CREATE TRIGGER transport_daily_submission_immutable BEFORE UPDATE OR DELETE ON transport_daily_submissions FOR EACH ROW EXECUTE FUNCTION public.transport_daily_evidence_immutable();
CREATE TRIGGER transport_daily_review_immutable BEFORE UPDATE OR DELETE ON transport_daily_reviews FOR EACH ROW EXECUTE FUNCTION public.transport_daily_evidence_immutable();
SQL);
        // Alphabetically first: lock the school before domain validation locks.
        foreach ($this->guarded as $table) {
            DB::unprepared("CREATE TRIGGER aaa_transport_report_scope_lock BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION public.transport_report_scope_lock()");
        }
    }

    public function down(): void
    {
        if (DB::table('transport_daily_submissions')->exists() || DB::table('transport_daily_reviews')->exists()) {
            throw new RuntimeException('Populated daily reporting evidence cannot be rolled back.');
        }
        foreach ($this->guarded as $table) {
            DB::unprepared("DROP TRIGGER aaa_transport_report_scope_lock ON {$table}");
        }
        DB::unprepared(<<<'SQL'
DROP TABLE transport_daily_reviews;
DROP TABLE transport_daily_submissions;
DROP FUNCTION public.transport_daily_review_validate();
DROP FUNCTION public.transport_daily_submission_validate();
DROP FUNCTION public.transport_daily_evidence_immutable();
DROP FUNCTION public.transport_daily_report_snapshot(uuid,uuid);
DROP FUNCTION public.transport_report_scope_lock();
SQL);
    }
};
