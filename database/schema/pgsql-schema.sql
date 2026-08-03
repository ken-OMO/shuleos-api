--
-- PostgreSQL database dump
--

\restrict gAa8IvfBKlnO8v9kxaH388nsb5lLOducN2wgTj5xlkYaNpAWO7ydyNPc7bjarzz

-- Dumped from database version 18.4
-- Dumped by pg_dump version 18.4

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: uuid-ossp; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS "uuid-ossp" WITH SCHEMA public;


--
-- Name: EXTENSION "uuid-ossp"; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION "uuid-ossp" IS 'generate universally unique identifiers (UUIDs)';


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: academic_weeks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.academic_weeks (
    id uuid NOT NULL,
    school_id uuid NOT NULL,
    academic_year_id uuid NOT NULL,
    term_id uuid NOT NULL,
    week_number integer NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: academic_years; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.academic_years (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    year_name character varying(20) NOT NULL,
    start_date date,
    end_date date,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: ai_assistants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_assistants (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    assistant_code character varying(100) NOT NULL,
    assistant_name character varying(255) NOT NULL,
    description text,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: ai_conversations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_conversations (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    user_id uuid NOT NULL,
    assistant_id uuid NOT NULL,
    title character varying(255),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: ai_generated_content; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_generated_content (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    content_type character varying(100),
    title character varying(255),
    generated_content text,
    created_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: ai_insights; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_insights (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    insight_type character varying(100),
    insight_title character varying(255),
    insight_description text,
    severity character varying(20),
    generated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: ai_prompts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_prompts (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    conversation_id uuid NOT NULL,
    prompt_text text NOT NULL,
    response_text text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: analytics_dimensions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.analytics_dimensions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    dimension_type character varying(100),
    dimension_name character varying(255),
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: api_access_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.api_access_logs (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    user_id uuid,
    endpoint character varying(255),
    request_method character varying(10),
    response_status integer,
    ip_address character varying(100),
    request_time timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: api_clients; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.api_clients (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid,
    client_name character varying(255) NOT NULL,
    client_type character varying(50),
    api_key character varying(255) NOT NULL,
    api_secret character varying(255),
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: api_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.api_tokens (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    user_id uuid NOT NULL,
    token text NOT NULL,
    expires_at timestamp without time zone,
    revoked boolean DEFAULT false,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: approvals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.approvals (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    entity_type character varying(100) NOT NULL,
    entity_id uuid NOT NULL,
    approver_id uuid NOT NULL,
    approval_status character varying(50) DEFAULT 'Pending'::character varying,
    comments text,
    approved_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: assessment_registrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.assessment_registrations (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    learner_id uuid NOT NULL,
    assessment_type character varying(50) NOT NULL,
    assessment_year integer NOT NULL,
    candidate_number character varying(100),
    registration_number character varying(100),
    status character varying(30),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    school_id uuid NOT NULL,
    created_by uuid,
    is_deleted boolean DEFAULT false NOT NULL,
    deleted_at timestamp(0) without time zone,
    deleted_by uuid
);


--
-- Name: assessment_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.assessment_types (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    assessment_type_name character varying(100) NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    is_deleted boolean DEFAULT false NOT NULL,
    deleted_at timestamp(0) without time zone,
    deleted_by uuid
);


--
-- Name: attendance_alerts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.attendance_alerts (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    attendance_id uuid NOT NULL,
    parent_notified boolean DEFAULT false,
    notification_method character varying(50),
    notified_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: attendance_sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.attendance_sessions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    session_name character varying(50) NOT NULL,
    session_order integer,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: attendance_statuses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.attendance_statuses (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    status_name character varying(50) NOT NULL,
    status_code character varying(10) NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: audit_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_logs (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid,
    user_id uuid,
    action character varying(255),
    table_name character varying(255),
    record_id uuid,
    old_values jsonb,
    new_values jsonb,
    ip_address character varying(100),
    user_agent text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    module character varying(255) NOT NULL,
    description text
);


--
-- Name: backup_files; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.backup_files (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    backup_job_id uuid NOT NULL,
    file_name character varying(255),
    storage_path text,
    backup_date timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: backup_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.backup_jobs (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    started_at timestamp without time zone,
    completed_at timestamp without time zone,
    status character varying(30),
    file_size_mb numeric(12,2),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: backup_policies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.backup_policies (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    backup_frequency character varying(20) NOT NULL,
    retention_days integer DEFAULT 30,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: bed_allocations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.bed_allocations (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    learner_id uuid NOT NULL,
    bed_id uuid NOT NULL,
    allocation_date date NOT NULL,
    release_date date,
    active boolean DEFAULT true,
    allocated_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: benchmark_groups; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.benchmark_groups (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    group_name character varying(255),
    description text,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: benchmark_metrics; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.benchmark_metrics (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    metric_code character varying(100),
    metric_name character varying(255),
    description text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: book_categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.book_categories (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    category_name character varying(255) NOT NULL,
    description text,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: book_copies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.book_copies (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    book_id uuid NOT NULL,
    accession_number character varying(100) NOT NULL,
    barcode character varying(100),
    status character varying(30) DEFAULT 'AVAILABLE'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: book_issues; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.book_issues (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    copy_id uuid NOT NULL,
    learner_id uuid,
    teacher_id uuid,
    issue_date date NOT NULL,
    due_date date NOT NULL,
    issued_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: book_returns; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.book_returns (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    issue_id uuid NOT NULL,
    return_date date NOT NULL,
    condition_on_return character varying(50),
    remarks text,
    received_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: books; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.books (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    category_id uuid NOT NULL,
    isbn character varying(50),
    title character varying(255) NOT NULL,
    author character varying(255),
    publisher character varying(255),
    publication_year integer,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: brand_domains; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.brand_domains (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    brand_id uuid NOT NULL,
    domain_name character varying(255) NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: brand_packages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.brand_packages (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    brand_id uuid NOT NULL,
    package_name character varying(255),
    amount numeric(12,2),
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: brand_schools; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.brand_schools (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    brand_id uuid NOT NULL,
    school_id uuid NOT NULL,
    joined_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: broadcasts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.broadcasts (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    title character varying(255) NOT NULL,
    message_body text NOT NULL,
    channel_id uuid NOT NULL,
    target_group character varying(100),
    total_recipients integer DEFAULT 0,
    total_sms_used integer DEFAULT 0,
    status character varying(30) DEFAULT 'DRAFT'::character varying,
    scheduled_at timestamp without time zone,
    sent_at timestamp without time zone,
    created_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: class_teachers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.class_teachers (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    teacher_id uuid NOT NULL,
    grade_id uuid NOT NULL,
    stream_id uuid NOT NULL,
    academic_year_id uuid NOT NULL,
    term_id uuid NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: comment_bank; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.comment_bank (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    grading_system_id uuid NOT NULL,
    min_score numeric(5,2),
    max_score numeric(5,2),
    grade_code character varying(20),
    comment_type character varying(50),
    comment_text text NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: communication_channels; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.communication_channels (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    channel_code character varying(50) NOT NULL,
    channel_name character varying(100) NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: communication_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.communication_logs (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid,
    parent_id uuid,
    channel_id uuid NOT NULL,
    recipient character varying(255) NOT NULL,
    subject character varying(255),
    message_body text NOT NULL,
    delivery_status character varying(30),
    sent_by uuid,
    sent_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: communication_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.communication_templates (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    channel_id uuid NOT NULL,
    template_name character varying(255) NOT NULL,
    template_code character varying(100),
    message_body text NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: curriculum_coverage; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.curriculum_coverage (
    id uuid NOT NULL,
    school_id uuid NOT NULL,
    teacher_assignment_id uuid NOT NULL,
    scheme_id uuid NOT NULL,
    scheme_lesson_id uuid NOT NULL,
    record_of_work_id uuid NOT NULL,
    date_completed date NOT NULL,
    strand character varying(255) NOT NULL,
    sub_strand character varying(255) NOT NULL,
    week_number integer NOT NULL,
    completed boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    is_deleted boolean DEFAULT false NOT NULL,
    deleted_at timestamp(0) without time zone,
    deleted_by uuid
);


--
-- Name: dashboard_preferences; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dashboard_preferences (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    user_id uuid NOT NULL,
    widget_id uuid NOT NULL,
    visible boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: dashboard_widgets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dashboard_widgets (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    widget_code character varying(100) NOT NULL,
    widget_name character varying(255) NOT NULL,
    widget_type character varying(50),
    active boolean DEFAULT true,
    display_order integer DEFAULT 0,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: discipline_actions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.discipline_actions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    case_id uuid NOT NULL,
    action_type character varying(100) NOT NULL,
    action_date date NOT NULL,
    remarks text,
    assigned_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: discipline_cases; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.discipline_cases (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    category_id uuid NOT NULL,
    reported_by uuid,
    incident_date date NOT NULL,
    incident_time time without time zone,
    location character varying(255),
    description text NOT NULL,
    status character varying(50) DEFAULT 'OPEN'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: discipline_categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.discipline_categories (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    category_name character varying(255) NOT NULL,
    description text,
    severity_level character varying(20),
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: document_categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.document_categories (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    category_name character varying(255) NOT NULL,
    description text,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.documents (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    category_id uuid NOT NULL,
    document_name character varying(255) NOT NULL,
    file_path text,
    file_type character varying(50),
    file_size bigint,
    uploaded_by uuid,
    uploaded_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: education_levels; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.education_levels (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    level_name character varying(100) NOT NULL,
    level_order integer NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: exam_invigilators; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.exam_invigilators (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    exam_id uuid NOT NULL,
    teacher_id uuid NOT NULL,
    grade_id uuid,
    stream_id uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: exam_learning_areas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.exam_learning_areas (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    exam_id uuid NOT NULL,
    learning_area_id uuid NOT NULL,
    number_of_papers integer DEFAULT 1,
    total_marks integer DEFAULT 100,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    is_deleted boolean DEFAULT false NOT NULL,
    deleted_at timestamp(0) without time zone,
    deleted_by uuid
);


--
-- Name: exam_papers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.exam_papers (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    exam_learning_area_id uuid NOT NULL,
    paper_name character varying(100),
    paper_number integer,
    max_marks integer DEFAULT 100,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    is_deleted boolean DEFAULT false NOT NULL,
    deleted_at timestamp(0) without time zone,
    deleted_by uuid
);


--
-- Name: exam_results; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.exam_results (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    exam_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    learning_area_id uuid NOT NULL,
    paper_id uuid,
    marks numeric(6,2),
    entered_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    is_deleted boolean DEFAULT false NOT NULL,
    deleted_at timestamp(0) without time zone,
    deleted_by uuid
);


--
-- Name: exams; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.exams (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    exam_name character varying(255) NOT NULL,
    assessment_type_id uuid NOT NULL,
    academic_year_id uuid NOT NULL,
    term_id uuid NOT NULL,
    start_date date,
    end_date date,
    active boolean DEFAULT true,
    created_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    is_deleted boolean DEFAULT false NOT NULL,
    deleted_at timestamp(0) without time zone,
    deleted_by uuid
);


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: fee_arrears; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fee_arrears (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    academic_year_id uuid NOT NULL,
    term_id uuid NOT NULL,
    amount numeric(12,2) NOT NULL,
    carried_forward_to_term_id uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: fee_categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fee_categories (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    category_name character varying(255) NOT NULL,
    description text,
    is_system boolean DEFAULT false,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: fee_discounts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fee_discounts (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    discount_name character varying(255) NOT NULL,
    discount_type character varying(20) NOT NULL,
    discount_value numeric(12,2) NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: fee_invoice_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fee_invoice_items (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    invoice_id uuid NOT NULL,
    fee_category_id uuid NOT NULL,
    amount numeric(12,2) NOT NULL,
    notes text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: fee_invoices; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fee_invoices (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    academic_year_id uuid NOT NULL,
    term_id uuid NOT NULL,
    invoice_number character varying(100) NOT NULL,
    total_amount numeric(12,2) NOT NULL,
    amount_paid numeric(12,2) DEFAULT 0,
    balance numeric(12,2) NOT NULL,
    status character varying(50) DEFAULT 'UNPAID'::character varying,
    invoice_date date NOT NULL,
    due_date date,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    grade_id uuid,
    stream_id uuid,
    fee_structure_id uuid,
    generated_by uuid,
    notes text,
    posted_at timestamp(0) without time zone,
    cancelled_at timestamp(0) without time zone
);


--
-- Name: fee_refunds; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fee_refunds (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    payment_id uuid,
    refund_amount numeric(12,2) NOT NULL,
    reason text,
    approved_by uuid,
    refund_date date NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: fee_structures; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fee_structures (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    academic_year_id uuid NOT NULL,
    term_id uuid NOT NULL,
    grade_id uuid NOT NULL,
    fee_category_id uuid NOT NULL,
    payment_plan_id uuid,
    amount numeric(12,2) NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    stream_id uuid,
    due_date date,
    notes text
);


--
-- Name: fee_transaction_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fee_transaction_types (
    id uuid NOT NULL,
    transaction_type character varying(50)
);


--
-- Name: finance_adjustments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.finance_adjustments (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    adjustment_type character varying(50) NOT NULL,
    amount numeric(12,2) NOT NULL,
    reason text NOT NULL,
    approved_by uuid,
    created_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL
);


--
-- Name: finance_audit_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.finance_audit_logs (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    user_id uuid NOT NULL,
    action character varying(100) NOT NULL,
    table_name character varying(100),
    record_id uuid,
    old_values jsonb,
    new_values jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    ip_address character varying(255),
    user_agent text
);


--
-- Name: finance_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.finance_settings (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    allow_partial_payments boolean DEFAULT true,
    allow_overpayments boolean DEFAULT false,
    auto_generate_invoices boolean DEFAULT true,
    require_fee_clearance_for_results boolean DEFAULT false,
    require_fee_clearance_for_report_cards boolean DEFAULT false,
    require_fee_clearance_for_exams boolean DEFAULT false,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    currency character varying(255) DEFAULT 'KES'::character varying NOT NULL
);


--
-- Name: grades; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.grades (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    grade_name character varying(50) NOT NULL,
    grade_order integer NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    education_level_id uuid
);


--
-- Name: grading_scales; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.grading_scales (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    grading_system_id uuid NOT NULL,
    grade_code character varying(20) NOT NULL,
    grade_description character varying(255),
    min_score numeric(5,2),
    max_score numeric(5,2),
    points integer,
    sort_order integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: grading_systems; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.grading_systems (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    grading_name character varying(255) NOT NULL,
    education_level_id uuid NOT NULL,
    uses_points boolean DEFAULT false,
    uses_marks boolean DEFAULT true,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: group_announcements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.group_announcements (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    group_id uuid NOT NULL,
    title character varying(255) NOT NULL,
    message_body text NOT NULL,
    created_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: group_targets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.group_targets (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    announcement_id uuid NOT NULL,
    school_id uuid NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: group_users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.group_users (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    group_id uuid NOT NULL,
    user_id uuid NOT NULL,
    role_name character varying(100),
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: guidance_counselling_records; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.guidance_counselling_records (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    session_date date NOT NULL,
    session_type character varying(100),
    notes text,
    counsellor_id uuid,
    follow_up_date date,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: hod_assignments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hod_assignments (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    teacher_id uuid NOT NULL,
    learning_area_id uuid NOT NULL,
    academic_year_id uuid NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: hostel_attendance; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hostel_attendance (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    attendance_date date NOT NULL,
    attendance_session character varying(20) NOT NULL,
    status character varying(20) NOT NULL,
    recorded_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: hostel_beds; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hostel_beds (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    room_id uuid NOT NULL,
    bed_number character varying(50) NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: hostel_incidents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hostel_incidents (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    incident_date date NOT NULL,
    incident_type character varying(100),
    description text NOT NULL,
    action_taken text,
    reported_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: hostel_rooms; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hostel_rooms (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    hostel_id uuid NOT NULL,
    room_name character varying(100) NOT NULL,
    floor_number integer,
    capacity integer,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: hostel_staff_assignments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hostel_staff_assignments (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    hostel_id uuid NOT NULL,
    teacher_id uuid NOT NULL,
    role_name character varying(100) NOT NULL,
    start_date date,
    end_date date,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: hostels; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hostels (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    hostel_name character varying(255) NOT NULL,
    hostel_type character varying(20) NOT NULL,
    capacity integer,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_hostel_type CHECK (((hostel_type)::text = ANY ((ARRAY['BOYS'::character varying, 'GIRLS'::character varying, 'MIXED'::character varying])::text[])))
);


--
-- Name: integration_providers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.integration_providers (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    provider_name character varying(255) NOT NULL,
    provider_type character varying(50),
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: inventory_categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.inventory_categories (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    category_name character varying(255) NOT NULL,
    description text,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: inventory_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.inventory_items (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    category_id uuid NOT NULL,
    item_code character varying(100),
    item_name character varying(255) NOT NULL,
    description text,
    unit_of_measure character varying(50),
    minimum_stock integer DEFAULT 0,
    current_stock integer DEFAULT 0,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: inventory_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.inventory_transactions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    item_id uuid NOT NULL,
    transaction_type character varying(30),
    quantity integer NOT NULL,
    balance_after integer,
    reference_type character varying(50),
    reference_id uuid,
    transaction_date timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: learner_attendance; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.learner_attendance (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    grade_id uuid NOT NULL,
    stream_id uuid NOT NULL,
    attendance_session_id uuid NOT NULL,
    attendance_status_id uuid NOT NULL,
    attendance_date date NOT NULL,
    remarks text,
    marked_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone
);


--
-- Name: learner_discounts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.learner_discounts (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    learner_id uuid NOT NULL,
    discount_id uuid NOT NULL,
    academic_year_id uuid NOT NULL,
    term_id uuid,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: learner_documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.learner_documents (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    learner_id uuid NOT NULL,
    document_id uuid NOT NULL,
    document_number character varying(100),
    issue_date date,
    expiry_date date,
    remarks text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    school_id uuid NOT NULL,
    created_by uuid,
    updated_at timestamp without time zone,
    updated_by uuid
);


--
-- Name: learner_fee_accounts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.learner_fee_accounts (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    account_number character varying(50),
    current_balance numeric(12,2) DEFAULT 0,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    credit_limit numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    last_payment_date date,
    account_status character varying(255) DEFAULT 'active'::character varying NOT NULL
);


--
-- Name: learner_fee_ledger; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.learner_fee_ledger (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    academic_year_id uuid,
    term_id uuid,
    transaction_date timestamp without time zone NOT NULL,
    transaction_type character varying(50) NOT NULL,
    reference_type character varying(50),
    reference_id uuid,
    debit_amount numeric(12,2) DEFAULT 0,
    credit_amount numeric(12,2) DEFAULT 0,
    running_balance numeric(12,2),
    description text,
    created_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: learner_medical_profiles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.learner_medical_profiles (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    learner_id uuid NOT NULL,
    blood_group character varying(10),
    allergies text,
    chronic_conditions text,
    emergency_contact character varying(50),
    notes text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: learner_parents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.learner_parents (
    id uuid NOT NULL,
    learner_id uuid NOT NULL,
    parent_id uuid NOT NULL,
    is_primary_contact boolean DEFAULT false NOT NULL,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: learner_transport_assignments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.learner_transport_assignments (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    learner_id uuid NOT NULL,
    route_id uuid NOT NULL,
    stop_id uuid NOT NULL,
    start_date date,
    end_date date,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: learners; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.learners (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    admission_no character varying(50) NOT NULL,
    upi character varying(50),
    first_name character varying(100) NOT NULL,
    middle_name character varying(100),
    last_name character varying(100) NOT NULL,
    gender character varying(20),
    date_of_birth date,
    grade_id uuid NOT NULL,
    stream_id uuid NOT NULL,
    admission_date date,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    assessment_no character varying(50),
    is_deleted boolean DEFAULT false,
    deleted_at timestamp without time zone,
    deleted_by uuid
);


--
-- Name: learning_area_allocations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.learning_area_allocations (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    grade_id uuid NOT NULL,
    learning_area_id uuid NOT NULL,
    lessons_per_week integer NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: learning_area_analysis; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.learning_area_analysis (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    exam_id uuid NOT NULL,
    learning_area_id uuid NOT NULL,
    grade_id uuid,
    stream_id uuid,
    learners_assessed integer,
    highest_score numeric(6,2),
    lowest_score numeric(6,2),
    mean_score numeric(6,2),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: learning_area_constraints; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.learning_area_constraints (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learning_area_id uuid NOT NULL,
    grade_id uuid,
    stream_id uuid,
    constraint_type character varying(100) NOT NULL,
    constraint_value character varying(255),
    priority character varying(20) DEFAULT 'NORMAL'::character varying,
    active boolean DEFAULT true,
    notes text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: learning_area_relationships; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.learning_area_relationships (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learning_area_1_id uuid NOT NULL,
    learning_area_2_id uuid NOT NULL,
    grade_id uuid,
    relationship_type character varying(100) NOT NULL,
    priority integer DEFAULT 1,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: learning_areas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.learning_areas (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    learning_area_name character varying(255) NOT NULL,
    short_name character varying(50),
    category character varying(100),
    is_core boolean DEFAULT true,
    is_examined boolean DEFAULT true,
    is_custom boolean DEFAULT false,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: lesson_notes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lesson_notes (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    lesson_plan_id uuid NOT NULL,
    note_content text NOT NULL,
    created_by uuid NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    is_deleted boolean DEFAULT false NOT NULL,
    deleted_at timestamp(0) without time zone,
    deleted_by uuid
);


--
-- Name: lesson_plans; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lesson_plans (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    teacher_assignment_id uuid NOT NULL,
    scheme_lesson_id uuid NOT NULL,
    lesson_date date NOT NULL,
    introduction text,
    lesson_development text,
    conclusion text,
    reflection text,
    status character varying(50) DEFAULT 'Draft'::character varying,
    created_by uuid NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    is_deleted boolean DEFAULT false NOT NULL,
    deleted_at timestamp(0) without time zone,
    deleted_by uuid
);


--
-- Name: level_learning_areas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.level_learning_areas (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    level_id uuid NOT NULL,
    learning_area_id uuid NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: library_fines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.library_fines (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid,
    issue_id uuid,
    amount numeric(12,2) NOT NULL,
    reason text,
    paid boolean DEFAULT false,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: license_partners; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.license_partners (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    partner_name character varying(255) NOT NULL,
    contact_person character varying(255),
    email character varying(255),
    phone character varying(50),
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: mark_entry_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.mark_entry_permissions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    exam_id uuid NOT NULL,
    role_name character varying(100) NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    opens_at timestamp(0) without time zone,
    closes_at timestamp(0) without time zone,
    is_deleted boolean DEFAULT false NOT NULL,
    deleted_at timestamp(0) without time zone,
    deleted_by uuid
);


--
-- Name: marketplace_categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.marketplace_categories (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    category_name character varying(255) NOT NULL,
    description text,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: marketplace_modules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.marketplace_modules (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    category_id uuid NOT NULL,
    module_code character varying(100) NOT NULL,
    module_name character varying(255) NOT NULL,
    description text,
    module_type character varying(30),
    monthly_price numeric(12,2) DEFAULT 0,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: medical_conditions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.medical_conditions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    condition_name character varying(255) NOT NULL,
    description text,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: medical_referrals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.medical_referrals (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    referral_date date NOT NULL,
    referred_to character varying(255),
    reason text,
    follow_up_notes text,
    created_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: medication_administration; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.medication_administration (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    visit_id uuid NOT NULL,
    medication_id uuid NOT NULL,
    dosage character varying(100),
    administered_by uuid,
    administered_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    notes text
);


--
-- Name: medications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.medications (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    medication_name character varying(255) NOT NULL,
    quantity_available integer DEFAULT 0,
    expiry_date date,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: merit_lists; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.merit_lists (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    exam_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    grade_id uuid NOT NULL,
    stream_id uuid,
    total_score numeric(6,2),
    total_points numeric(6,2),
    stream_position integer,
    grade_position integer,
    school_position integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: module_installations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.module_installations (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    module_id uuid NOT NULL,
    installed_by uuid,
    installation_date timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    status character varying(30)
);


--
-- Name: module_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.module_versions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    module_id uuid NOT NULL,
    version_number character varying(50),
    release_notes text,
    release_date date,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: mpesa_callback_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.mpesa_callback_logs (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    transaction_id uuid,
    payload jsonb NOT NULL,
    received_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: mpesa_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.mpesa_transactions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid,
    payment_id uuid,
    gateway_id uuid NOT NULL,
    merchant_request_id character varying(255),
    checkout_request_id character varying(255),
    mpesa_receipt_number character varying(100),
    phone_number character varying(20),
    account_reference character varying(100),
    transaction_desc text,
    amount numeric(12,2) NOT NULL,
    transaction_date timestamp without time zone,
    result_code integer,
    result_description text,
    status character varying(50) DEFAULT 'PENDING'::character varying,
    callback_payload jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    processed_by uuid,
    is_reconciled boolean DEFAULT false NOT NULL,
    reconciled_at timestamp(0) without time zone
);


--
-- Name: schools; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.schools (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_name character varying(255) NOT NULL,
    school_code character varying(50) NOT NULL,
    email character varying(255),
    phone character varying(30),
    county character varying(100),
    sub_county character varying(100),
    postal_address character varying(100),
    physical_address text,
    logo_url text,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    school_type character varying(50),
    ownership character varying(50),
    registration_number character varying(100),
    kra_pin character varying(50),
    website character varying(255),
    is_deleted boolean DEFAULT false,
    deleted_at timestamp without time zone,
    deleted_by uuid
);


--
-- Name: mv_school_dashboard; Type: MATERIALIZED VIEW; Schema: public; Owner: -
--

CREATE MATERIALIZED VIEW public.mv_school_dashboard AS
 SELECT s.id AS school_id,
    s.school_name,
    count(DISTINCT l.id) AS learner_count
   FROM (public.schools s
     LEFT JOIN public.learners l ON ((l.school_id = s.id)))
  WHERE (COALESCE(l.is_deleted, false) = false)
  GROUP BY s.id, s.school_name
  WITH NO DATA;


--
-- Name: notification_preferences; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_preferences (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    attendance_alerts boolean DEFAULT true,
    fee_reminders boolean DEFAULT true,
    exam_results boolean DEFAULT true,
    discipline_alerts boolean DEFAULT true,
    parent_meeting_alerts boolean DEFAULT true,
    report_card_alerts boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notifications (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    user_id uuid NOT NULL,
    title character varying(255) NOT NULL,
    message text NOT NULL,
    is_read boolean DEFAULT false,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: online_applications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.online_applications (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    applicant_name character varying(255) NOT NULL,
    date_of_birth date,
    parent_name character varying(255),
    parent_phone character varying(50),
    grade_applied_for uuid,
    status character varying(30) DEFAULT 'PENDING'::character varying,
    submitted_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: package_features; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.package_features (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    package_id uuid NOT NULL,
    feature_id uuid NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: parent_learner_links; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.parent_learner_links (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    parent_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: parent_meetings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.parent_meetings (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    meeting_date timestamp without time zone NOT NULL,
    purpose text NOT NULL,
    outcome text,
    created_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: parents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.parents (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    user_id uuid,
    first_name character varying(100) NOT NULL,
    last_name character varying(100) NOT NULL,
    phone character varying(30) NOT NULL,
    email character varying(255),
    relationship character varying(50),
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    is_deleted boolean DEFAULT false,
    deleted_at timestamp without time zone,
    deleted_by uuid
);


--
-- Name: pathway_recommendations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pathway_recommendations (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    learner_id uuid NOT NULL,
    academic_year_id uuid NOT NULL,
    recommendation_date date,
    recommended_pathway character varying(100),
    confidence_score numeric(5,2),
    strengths text,
    improvement_areas text,
    generated_by character varying(50) DEFAULT 'SYSTEM'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: payment_allocations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payment_allocations (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    payment_id uuid NOT NULL,
    invoice_id uuid CONSTRAINT payment_allocations_invoice_item_id_not_null NOT NULL,
    allocated_amount numeric(12,2) CONSTRAINT payment_allocations_amount_not_null NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    school_id uuid,
    created_by uuid
);


--
-- Name: payment_methods; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payment_methods (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    method_name character varying(100) NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    is_online boolean DEFAULT false NOT NULL
);


--
-- Name: payment_plan_installments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payment_plan_installments (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    payment_plan_id uuid NOT NULL,
    installment_name character varying(100) NOT NULL,
    percentage numeric(5,2) NOT NULL,
    due_days integer,
    installment_order integer NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: payment_plans; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payment_plans (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    plan_name character varying(255) NOT NULL,
    description text,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    number_of_installments integer DEFAULT 1 NOT NULL
);


--
-- Name: payments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payments (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    invoice_id uuid,
    payment_method_id uuid NOT NULL,
    receipt_number character varying(100) NOT NULL,
    amount numeric(12,2) NOT NULL,
    transaction_reference character varying(255),
    payment_date timestamp without time zone NOT NULL,
    received_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    payment_status character varying(20) DEFAULT 'COMPLETED'::character varying,
    reversed boolean DEFAULT false,
    reversal_reason text,
    reversed_at timestamp without time zone,
    reversed_by uuid,
    payer_phone character varying(20),
    payer_name character varying(255),
    remarks text,
    posted_by uuid,
    allocated_amount numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    payment_channel character varying(255)
);


--
-- Name: permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.permissions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    permission_name character varying(255) NOT NULL,
    module_name character varying(100),
    description text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: platform_audit_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.platform_audit_logs (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    user_id uuid,
    action character varying(255),
    entity_name character varying(100),
    entity_id uuid,
    old_values jsonb,
    new_values jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: records_of_work; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.records_of_work (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    lesson_plan_id uuid NOT NULL,
    date_taught date NOT NULL,
    content_covered text,
    learner_response text,
    teacher_reflection text,
    status character varying(50) DEFAULT 'Pending'::character varying,
    created_by uuid NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    is_deleted boolean DEFAULT false NOT NULL,
    deleted_at timestamp(0) without time zone,
    deleted_by uuid
);


--
-- Name: reference_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.reference_types (
    id uuid NOT NULL,
    reference_type character varying(50)
);


--
-- Name: report_card_learning_areas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.report_card_learning_areas (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    report_card_id uuid NOT NULL,
    learning_area_id uuid NOT NULL,
    score numeric(6,2),
    grade_code character varying(20),
    points integer,
    teacher_comment text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: report_cards; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.report_cards (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    exam_id uuid NOT NULL,
    academic_year_id uuid NOT NULL,
    term_id uuid NOT NULL,
    overall_score numeric(6,2),
    overall_grade character varying(20),
    total_points numeric(6,2),
    stream_position integer,
    grade_position integer,
    school_position integer,
    total_learners integer,
    attendance_percentage numeric(5,2),
    class_teacher_comment text,
    principal_comment text,
    pathway_recommendation text,
    generated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: report_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.report_templates (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    template_name character varying(255) NOT NULL,
    education_level_id uuid,
    is_default boolean DEFAULT false,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: restore_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.restore_logs (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    restore_request_id uuid NOT NULL,
    action_taken text,
    performed_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: restore_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.restore_requests (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    backup_file_id uuid NOT NULL,
    requested_by uuid,
    status character varying(30) DEFAULT 'PENDING'::character varying,
    requested_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    completed_at timestamp without time zone
);


--
-- Name: role_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.role_permissions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    role_id uuid NOT NULL,
    permission_id uuid NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roles (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    role_name character varying(100) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: room_constraints; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.room_constraints (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    room_id uuid NOT NULL,
    learning_area_id uuid,
    constraint_type character varying(100) NOT NULL,
    constraint_value character varying(255),
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_room_constraint_type CHECK (((constraint_type)::text = ANY ((ARRAY['ROOM_REQUIRED'::character varying, 'ROOM_EXCLUSIVE'::character varying, 'LAB_ONLY'::character varying, 'CAPACITY_LIMIT'::character varying, 'SPECIAL_EQUIPMENT'::character varying, 'SUBJECT_ONLY'::character varying])::text[])))
);


--
-- Name: room_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.room_types (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    type_name character varying(100) NOT NULL,
    description text,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: rooms; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rooms (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    room_name character varying(255) NOT NULL,
    capacity integer,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    room_type_id uuid,
    room_code character varying(50),
    block_name character varying(100),
    floor_number integer,
    created_by uuid,
    CONSTRAINT chk_floor_number CHECK (((floor_number IS NULL) OR (floor_number >= 0))),
    CONSTRAINT chk_room_capacity CHECK ((capacity > 0))
);


--
-- Name: route_stops; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.route_stops (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    route_id uuid NOT NULL,
    stop_name character varying(255) NOT NULL,
    stop_order integer NOT NULL,
    pickup_time time without time zone,
    dropoff_time time without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: saved_reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.saved_reports (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    report_name character varying(255) NOT NULL,
    report_type character varying(100),
    report_filters jsonb,
    created_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: scheme_lessons; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.scheme_lessons (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    week_id uuid NOT NULL,
    lesson_number integer NOT NULL,
    strand character varying(255) NOT NULL,
    sub_strand character varying(255) NOT NULL,
    specific_learning_outcome text,
    learning_experience text,
    resources text,
    assessment_method text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    scheme_id uuid NOT NULL,
    is_deleted boolean DEFAULT false NOT NULL,
    deleted_at timestamp(0) without time zone,
    deleted_by uuid
);


--
-- Name: scheme_weeks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.scheme_weeks (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    scheme_id uuid NOT NULL,
    week_number integer NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: schemes_of_work; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.schemes_of_work (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learning_area_id uuid NOT NULL,
    grade_id uuid NOT NULL,
    academic_year_id uuid NOT NULL,
    term_id uuid NOT NULL,
    title character varying(255),
    active boolean DEFAULT true,
    created_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    is_deleted boolean DEFAULT false NOT NULL,
    deleted_at timestamp(0) without time zone,
    deleted_by uuid
);


--
-- Name: school_benchmarks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.school_benchmarks (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    benchmark_group_id uuid NOT NULL,
    metric_id uuid NOT NULL,
    metric_value numeric(12,2),
    ranking integer,
    benchmark_date date,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: school_calendar; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.school_calendar (
    calendar_id integer NOT NULL,
    event_name character varying(255),
    event_type character varying(50),
    start_date date,
    end_date date,
    description text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: school_calendar_calendar_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.school_calendar_calendar_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: school_calendar_calendar_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.school_calendar_calendar_id_seq OWNED BY public.school_calendar.calendar_id;


--
-- Name: school_group_members; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.school_group_members (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    group_id uuid NOT NULL,
    school_id uuid NOT NULL,
    joined_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: school_groups; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.school_groups (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    group_name character varying(255) NOT NULL,
    registration_number character varying(100),
    contact_email character varying(255),
    contact_phone character varying(50),
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: school_integrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.school_integrations (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    provider_id uuid NOT NULL,
    configuration jsonb,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: school_learning_areas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.school_learning_areas (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learning_area_id uuid NOT NULL,
    is_examined boolean DEFAULT true,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: school_module_subscriptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.school_module_subscriptions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    module_id uuid NOT NULL,
    start_date date,
    expiry_date date,
    status character varying(30) DEFAULT 'ACTIVE'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: school_payment_gateways; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.school_payment_gateways (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    gateway_name character varying(50) NOT NULL,
    gateway_type character varying(50) NOT NULL,
    shortcode character varying(50),
    paybill_number character varying(50),
    till_number character varying(50),
    account_reference_format character varying(100),
    encrypted_consumer_key text,
    encrypted_consumer_secret text,
    encrypted_passkey text,
    initiator_name character varying(100),
    encrypted_security_credential text,
    callback_url text,
    validation_url text,
    confirmation_url text,
    environment character varying(20) DEFAULT 'SANDBOX'::character varying,
    active boolean DEFAULT true,
    is_default boolean DEFAULT false,
    created_by uuid,
    updated_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_environment CHECK (((environment)::text = ANY ((ARRAY['SANDBOX'::character varying, 'PRODUCTION'::character varying])::text[]))),
    CONSTRAINT chk_gateway_type CHECK (((gateway_type)::text = ANY ((ARRAY['MPESA_PAYBILL'::character varying, 'MPESA_TILL'::character varying, 'BANK'::character varying, 'PESALINK'::character varying, 'AIRTEL_MONEY'::character varying, 'CARD'::character varying])::text[])))
);


--
-- Name: school_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.school_settings (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    school_motto character varying(255),
    school_vision text,
    school_mission text,
    school_type character varying(50),
    ownership character varying(50),
    principal_name character varying(255),
    principal_signature_url text,
    deputy_principal_name character varying(255),
    school_logo_url text,
    report_header text,
    report_footer text,
    grading_system character varying(50),
    pathway_enabled boolean DEFAULT true,
    parent_portal_enabled boolean DEFAULT true,
    sms_notifications_enabled boolean DEFAULT false,
    email_notifications_enabled boolean DEFAULT true,
    attendance_enabled boolean DEFAULT true,
    library_enabled boolean DEFAULT false,
    inventory_enabled boolean DEFAULT false,
    finance_enabled boolean DEFAULT false,
    timetable_enabled boolean DEFAULT false,
    elections_enabled boolean DEFAULT false,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    primary_grading_system character varying(50),
    junior_grading_system character varying(50),
    subscription_package character varying(100),
    subscription_status character varying(50),
    school_health_score_enabled boolean DEFAULT true
);


--
-- Name: school_status_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.school_status_logs (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    old_status character varying(30),
    new_status character varying(30),
    reason text,
    changed_by uuid,
    changed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: school_subscriptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.school_subscriptions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    package_id uuid,
    start_date date,
    expiry_date date,
    grace_end_date date,
    status character varying(30) DEFAULT 'TRIAL'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    source character varying(30) DEFAULT 'PAYMENT'::character varying,
    activated_by uuid,
    amount_paid numeric(12,2),
    payment_reference character varying(100),
    is_trial boolean DEFAULT false,
    auto_renew boolean DEFAULT false,
    updated_at timestamp without time zone,
    updated_by uuid,
    CONSTRAINT chk_subscription_status CHECK (((status)::text = ANY ((ARRAY['TRIAL'::character varying, 'PENDING_PAYMENT'::character varying, 'ACTIVE'::character varying, 'GRACE_PERIOD'::character varying, 'LOCKED'::character varying, 'SUSPENDED'::character varying])::text[])))
);


--
-- Name: sick_bay_visits; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sick_bay_visits (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    visit_date timestamp without time zone NOT NULL,
    symptoms text,
    diagnosis text,
    action_taken text,
    attended_by uuid,
    sent_home boolean DEFAULT false,
    admitted boolean DEFAULT false,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: sms_credit_purchases; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sms_credit_purchases (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    sms_package_id uuid NOT NULL,
    sms_credits integer NOT NULL,
    amount_paid numeric(12,2) NOT NULL,
    mpesa_receipt_number character varying(100),
    payer_phone character varying(20),
    verification_status character varying(30) DEFAULT 'PENDING'::character varying,
    verified_by uuid,
    verified_at timestamp without time zone,
    purchase_date timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: sms_packages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sms_packages (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    package_name character varying(100) NOT NULL,
    sms_count integer NOT NULL,
    amount numeric(12,2) NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: sms_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sms_transactions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    communication_log_id uuid,
    learner_id uuid,
    parent_id uuid,
    phone_number character varying(20) NOT NULL,
    sms_units integer DEFAULT 1,
    provider_name character varying(100),
    provider_message_id character varying(255),
    delivery_status character varying(30),
    delivered_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: sms_wallets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sms_wallets (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    sms_balance integer DEFAULT 0,
    low_balance_threshold integer DEFAULT 20,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    total_purchased integer DEFAULT 0,
    total_consumed integer DEFAULT 0,
    total_amount_spent numeric(12,2) DEFAULT 0,
    status character varying(20) DEFAULT 'ACTIVE'::character varying,
    last_purchase_date timestamp without time zone,
    last_purchase_amount numeric(12,2),
    updated_by uuid
);


--
-- Name: staff_documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.staff_documents (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    teacher_id uuid NOT NULL,
    document_id uuid NOT NULL,
    document_number character varying(100),
    issue_date date,
    expiry_date date,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    school_id uuid NOT NULL,
    remarks text,
    created_by uuid,
    updated_at timestamp without time zone,
    updated_by uuid
);


--
-- Name: stock_adjustments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock_adjustments (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    item_id uuid NOT NULL,
    adjustment_type character varying(30),
    quantity integer NOT NULL,
    reason text,
    adjusted_by uuid,
    adjustment_date date NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: stock_issue_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock_issue_items (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    issue_id uuid NOT NULL,
    item_id uuid NOT NULL,
    quantity integer NOT NULL
);


--
-- Name: stock_issues; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock_issues (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    issue_number character varying(100),
    issue_date date NOT NULL,
    issued_to character varying(255),
    department character varying(255),
    issued_by uuid,
    notes text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: stock_receipt_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock_receipt_items (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    receipt_id uuid NOT NULL,
    item_id uuid NOT NULL,
    quantity integer NOT NULL,
    unit_cost numeric(12,2),
    total_cost numeric(12,2)
);


--
-- Name: stock_receipts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stock_receipts (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    supplier_id uuid,
    receipt_number character varying(100),
    receipt_date date NOT NULL,
    received_by uuid,
    notes text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: streams; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.streams (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    grade_id uuid NOT NULL,
    stream_name character varying(50) NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: subscription_features; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_features (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    feature_code character varying(100) NOT NULL,
    feature_name character varying(255) NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: subscription_packages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_packages (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    package_name character varying(100) NOT NULL,
    description text,
    amount numeric(12,2) NOT NULL,
    duration_months integer DEFAULT 4,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: subscription_payment_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_payment_requests (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    package_id uuid NOT NULL,
    amount numeric(12,2) NOT NULL,
    request_date timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    status character varying(30) DEFAULT 'PENDING'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    payment_reference character varying(100),
    payer_phone character varying(20),
    payer_name character varying(255),
    verified boolean DEFAULT false,
    verified_at timestamp without time zone,
    verified_by uuid,
    subscription_id uuid
);


--
-- Name: subscription_payments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_payments (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    payment_request_id uuid NOT NULL,
    mpesa_receipt_number character varying(100) NOT NULL,
    payer_phone character varying(20),
    amount numeric(12,2) NOT NULL,
    payment_date timestamp without time zone,
    verification_status character varying(30) DEFAULT 'PENDING'::character varying,
    verified_by uuid,
    verified_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: subscription_upgrade_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_upgrade_requests (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    current_package_id uuid,
    requested_package_id uuid NOT NULL,
    additional_amount numeric(12,2),
    status character varying(30) DEFAULT 'PENDING'::character varying,
    approved_by uuid,
    approved_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: super_admins; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.super_admins (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    user_id uuid NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: suppliers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.suppliers (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    supplier_name character varying(255) NOT NULL,
    contact_person character varying(255),
    phone character varying(50),
    email character varying(255),
    address text,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: support_ticket_comments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.support_ticket_comments (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    ticket_id uuid NOT NULL,
    comment_text text NOT NULL,
    commented_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: support_tickets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.support_tickets (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    ticket_number character varying(100) NOT NULL,
    subject character varying(255) NOT NULL,
    description text NOT NULL,
    priority character varying(20) DEFAULT 'MEDIUM'::character varying,
    status character varying(30) DEFAULT 'OPEN'::character varying,
    assigned_to uuid,
    created_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    resolved_at timestamp without time zone
);


--
-- Name: system_announcements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.system_announcements (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    title character varying(255) NOT NULL,
    message_body text NOT NULL,
    start_date date,
    end_date date,
    active boolean DEFAULT true,
    created_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: system_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.system_settings (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    setting_key character varying(100) NOT NULL,
    setting_value text,
    description text,
    updated_by uuid,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: teacher_assignments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.teacher_assignments (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    teacher_id uuid NOT NULL,
    learning_area_id uuid NOT NULL,
    grade_id uuid NOT NULL,
    stream_id uuid,
    academic_year_id uuid NOT NULL,
    term_id uuid NOT NULL,
    is_class_teacher boolean DEFAULT false,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    lessons_per_week integer,
    is_deleted boolean DEFAULT false NOT NULL,
    deleted_at timestamp(0) without time zone,
    deleted_by uuid
);


--
-- Name: teacher_availability; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.teacher_availability (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    teacher_id uuid NOT NULL,
    day_of_week integer NOT NULL,
    period_id uuid NOT NULL,
    is_available boolean DEFAULT true,
    remarks text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: teacher_constraints; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.teacher_constraints (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    teacher_id uuid NOT NULL,
    constraint_type character varying(100) NOT NULL,
    constraint_value character varying(255),
    priority character varying(20) DEFAULT 'NORMAL'::character varying,
    active boolean DEFAULT true,
    notes text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_teacher_constraint_type CHECK (((constraint_type)::text = ANY ((ARRAY['MAX_DAILY_LESSONS'::character varying, 'MAX_WEEKLY_LESSONS'::character varying, 'NO_MORNING'::character varying, 'NO_AFTERNOON'::character varying, 'NO_MONDAY'::character varying, 'NO_FRIDAY'::character varying, 'PREFERRED_DAY'::character varying, 'PREFERRED_PERIOD'::character varying, 'CONSECUTIVE_LIMIT'::character varying])::text[])))
);


--
-- Name: teachers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.teachers (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    user_id uuid NOT NULL,
    tsc_no character varying(50),
    staff_no character varying(50),
    gender character varying(20),
    designation character varying(100),
    employment_type character varying(50),
    phone character varying(30),
    email character varying(255),
    national_id character varying(50),
    date_joined date,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    is_deleted boolean DEFAULT false,
    deleted_at timestamp without time zone,
    deleted_by uuid
);


--
-- Name: terms; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.terms (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    academic_year_id uuid NOT NULL,
    term_name character varying(20) NOT NULL,
    start_date date,
    end_date date,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: timetable_conflicts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.timetable_conflicts (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    timetable_id uuid NOT NULL,
    conflict_type character varying(100) NOT NULL,
    severity character varying(20) DEFAULT 'HIGH'::character varying,
    description text NOT NULL,
    resolved boolean DEFAULT false,
    resolved_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_conflict_severity CHECK (((severity)::text = ANY ((ARRAY['LOW'::character varying, 'MEDIUM'::character varying, 'HIGH'::character varying, 'CRITICAL'::character varying])::text[]))),
    CONSTRAINT chk_conflict_type CHECK (((conflict_type)::text = ANY ((ARRAY['TEACHER_CLASH'::character varying, 'ROOM_CLASH'::character varying, 'OVERLOAD'::character varying, 'UNDERLOAD'::character varying, 'MISSING_TEACHER'::character varying, 'MISSING_ROOM'::character varying, 'INVALID_PERIOD'::character varying])::text[])))
);


--
-- Name: timetable_constraints; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.timetable_constraints (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    constraint_name character varying(255) NOT NULL,
    constraint_category character varying(50) NOT NULL,
    priority character varying(20) DEFAULT 'NORMAL'::character varying,
    active boolean DEFAULT true,
    description text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_timetable_category CHECK (((constraint_category)::text = ANY ((ARRAY['ACADEMIC'::character varying, 'TEACHER'::character varying, 'GRADE'::character varying, 'ROOM'::character varying, 'SCHOOL'::character varying])::text[]))),
    CONSTRAINT chk_timetable_priority CHECK (((priority)::text = ANY ((ARRAY['LOW'::character varying, 'MEDIUM'::character varying, 'HIGH'::character varying, 'CRITICAL'::character varying])::text[])))
);


--
-- Name: timetable_entries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.timetable_entries (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    timetable_id uuid NOT NULL,
    day_of_week integer NOT NULL,
    period_id uuid NOT NULL,
    grade_id uuid NOT NULL,
    stream_id uuid NOT NULL,
    learning_area_id uuid NOT NULL,
    teacher_id uuid NOT NULL,
    room_id uuid,
    is_double_lesson boolean DEFAULT false,
    remarks text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: timetable_generation_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.timetable_generation_runs (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    timetable_id uuid NOT NULL,
    generated_by uuid,
    generation_type character varying(50) DEFAULT 'AUTO'::character varying,
    status character varying(50) DEFAULT 'COMPLETED'::character varying,
    total_entries integer DEFAULT 0,
    total_conflicts integer DEFAULT 0,
    started_at timestamp without time zone,
    completed_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_generation_status CHECK (((status)::text = ANY ((ARRAY['PENDING'::character varying, 'RUNNING'::character varying, 'COMPLETED'::character varying, 'FAILED'::character varying])::text[]))),
    CONSTRAINT chk_generation_type CHECK (((generation_type)::text = ANY ((ARRAY['AUTO'::character varying, 'MANUAL'::character varying, 'REGENERATE'::character varying, 'OPTIMIZE'::character varying])::text[])))
);


--
-- Name: timetable_periods; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.timetable_periods (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    timetable_profile_id uuid NOT NULL,
    period_name character varying(100) NOT NULL,
    period_order integer NOT NULL,
    start_time time without time zone NOT NULL,
    end_time time without time zone NOT NULL,
    is_teaching_period boolean DEFAULT true,
    is_break boolean DEFAULT false,
    is_lunch boolean DEFAULT false,
    is_assembly boolean DEFAULT false,
    is_games boolean DEFAULT false,
    is_club boolean DEFAULT false,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: timetable_profiles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.timetable_profiles (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    profile_name character varying(255) NOT NULL,
    education_level_id uuid,
    periods_per_day integer NOT NULL,
    periods_per_week integer,
    lesson_duration_minutes integer NOT NULL,
    allow_double_lessons boolean DEFAULT false,
    use_cbc_template boolean DEFAULT true,
    active boolean DEFAULT true,
    is_default boolean DEFAULT false,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: timetable_publications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.timetable_publications (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    timetable_id uuid NOT NULL,
    publication_status character varying(50) NOT NULL,
    published_by uuid,
    published_at timestamp without time zone,
    notes text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_publication_status CHECK (((publication_status)::text = ANY ((ARRAY['DRAFT'::character varying, 'PUBLISHED'::character varying, 'ARCHIVED'::character varying])::text[])))
);


--
-- Name: timetable_substitutions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.timetable_substitutions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    timetable_entry_id uuid NOT NULL,
    absent_teacher_id uuid NOT NULL,
    substitute_teacher_id uuid NOT NULL,
    substitution_date date NOT NULL,
    reason text,
    approved_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: timetables; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.timetables (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    timetable_profile_id uuid NOT NULL,
    academic_year_id uuid NOT NULL,
    term_id uuid NOT NULL,
    timetable_name character varying(255) NOT NULL,
    status character varying(50) DEFAULT 'Draft'::character varying,
    active boolean DEFAULT true,
    created_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: transaction_reconciliations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.transaction_reconciliations (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    mpesa_transaction_id uuid NOT NULL,
    learner_id uuid,
    invoice_id uuid,
    reconciled boolean DEFAULT false,
    reconciled_by uuid,
    reconciled_at timestamp without time zone,
    notes text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: transport_attendance; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.transport_attendance (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    learner_id uuid NOT NULL,
    attendance_date date NOT NULL,
    trip_type character varying(20) NOT NULL,
    status character varying(20) NOT NULL,
    recorded_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: transport_incidents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.transport_incidents (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    vehicle_id uuid,
    incident_date timestamp without time zone NOT NULL,
    incident_type character varying(100),
    description text,
    action_taken text,
    reported_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: transport_routes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.transport_routes (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    route_name character varying(255) NOT NULL,
    description text,
    monthly_fee numeric(12,2),
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: user_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_roles (
    user_id uuid NOT NULL,
    role_id uuid NOT NULL
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    role_id uuid NOT NULL,
    username character varying(100) NOT NULL,
    password_hash text NOT NULL,
    email character varying(255),
    phone character varying(30),
    first_login boolean DEFAULT true,
    active boolean DEFAULT true,
    last_login timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    first_name character varying(100),
    middle_name character varying(100),
    last_name character varying(100),
    mfa_enabled boolean DEFAULT false,
    created_by uuid,
    updated_by uuid,
    is_deleted boolean DEFAULT false,
    deleted_at timestamp without time zone,
    deleted_by uuid,
    password_changed_at timestamp without time zone,
    failed_login_attempts integer DEFAULT 0,
    account_locked_until timestamp without time zone,
    password_reset_token text,
    password_reset_expires timestamp without time zone,
    mfa_secret text,
    last_failed_login timestamp without time zone
);


--
-- Name: vehicle_assignments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_assignments (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    vehicle_id uuid NOT NULL,
    route_id uuid NOT NULL,
    start_date date,
    end_date date,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: vehicles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicles (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    registration_number character varying(50) NOT NULL,
    vehicle_name character varying(255),
    capacity integer NOT NULL,
    driver_name character varying(255),
    driver_phone character varying(50),
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: vw_attendance_summary; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.vw_attendance_summary AS
 SELECT la.school_id,
    la.attendance_date,
    g.grade_name,
    st.stream_name,
    ast.status_name,
    count(*) AS learners
   FROM (((public.learner_attendance la
     LEFT JOIN public.grades g ON ((g.id = la.grade_id)))
     LEFT JOIN public.streams st ON ((st.id = la.stream_id)))
     LEFT JOIN public.attendance_statuses ast ON ((ast.id = la.attendance_status_id)))
  GROUP BY la.school_id, la.attendance_date, g.grade_name, st.stream_name, ast.status_name;


--
-- Name: vw_class_register; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.vw_class_register AS
 SELECT l.school_id,
    g.grade_name,
    st.stream_name,
    l.admission_no,
    concat_ws(' '::text, l.first_name, l.middle_name, l.last_name) AS learner_name,
    l.gender,
    l.active
   FROM ((public.learners l
     LEFT JOIN public.grades g ON ((g.id = l.grade_id)))
     LEFT JOIN public.streams st ON ((st.id = l.stream_id)))
  WHERE (COALESCE(l.is_deleted, false) = false);


--
-- Name: vw_exam_results; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.vw_exam_results AS
 SELECT er.id,
    e.exam_name,
    concat_ws(' '::text, l.first_name, l.middle_name, l.last_name) AS learner_name,
    la.learning_area_name,
    er.marks,
    er.created_at
   FROM (((public.exam_results er
     JOIN public.learners l ON ((l.id = er.learner_id)))
     JOIN public.exams e ON ((e.id = er.exam_id)))
     JOIN public.learning_areas la ON ((la.id = er.learning_area_id)));


--
-- Name: vw_fee_balances; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.vw_fee_balances AS
 SELECT learner_id,
    school_id,
    sum(debit_amount) AS total_charged,
    sum(credit_amount) AS total_paid,
    (sum(debit_amount) - sum(credit_amount)) AS balance
   FROM public.learner_fee_ledger
  GROUP BY learner_id, school_id;


--
-- Name: vw_learner_profile; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.vw_learner_profile AS
 SELECT l.id,
    l.school_id,
    s.school_name,
    l.admission_no,
    l.upi,
    concat_ws(' '::text, l.first_name, l.middle_name, l.last_name) AS learner_name,
    l.gender,
    l.date_of_birth,
    l.admission_date,
    g.grade_name,
    st.stream_name,
    l.active
   FROM (((public.learners l
     JOIN public.schools s ON ((s.id = l.school_id)))
     LEFT JOIN public.grades g ON ((g.id = l.grade_id)))
     LEFT JOIN public.streams st ON ((st.id = l.stream_id)))
  WHERE (COALESCE(l.is_deleted, false) = false);


--
-- Name: vw_school_dashboard; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.vw_school_dashboard AS
 SELECT s.id,
    s.school_name,
    count(DISTINCT l.id) AS learners,
    count(DISTINCT u.id) AS users,
    count(DISTINCT g.id) AS grades,
    count(DISTINCT st.id) AS streams
   FROM ((((public.schools s
     LEFT JOIN public.learners l ON ((l.school_id = s.id)))
     LEFT JOIN public.users u ON ((u.school_id = s.id)))
     LEFT JOIN public.grades g ON ((g.school_id = s.id)))
     LEFT JOIN public.streams st ON ((st.school_id = s.id)))
  GROUP BY s.id, s.school_name;


--
-- Name: vw_user_directory; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.vw_user_directory AS
 SELECT u.id,
    u.school_id,
    u.username,
    u.email,
    u.phone,
    concat_ws(' '::text, u.first_name, u.middle_name, u.last_name) AS full_name,
    u.active,
    u.last_login,
    r.role_name
   FROM (public.users u
     LEFT JOIN public.roles r ON ((r.id = u.role_id)))
  WHERE (COALESCE(u.is_deleted, false) = false);


--
-- Name: website_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.website_events (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    event_title character varying(255),
    description text,
    event_date date,
    location character varying(255),
    published boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: website_galleries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.website_galleries (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    album_name character varying(255),
    description text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: website_gallery_images; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.website_gallery_images (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    gallery_id uuid NOT NULL,
    image_url text NOT NULL,
    caption text,
    uploaded_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: website_news; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.website_news (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    title character varying(255) NOT NULL,
    summary text,
    content text,
    featured_image text,
    published boolean DEFAULT false,
    published_at timestamp without time zone,
    created_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: website_pages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.website_pages (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    page_title character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    page_content text,
    published boolean DEFAULT false,
    created_by uuid,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: website_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.website_settings (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    website_title character varying(255),
    website_subtitle text,
    domain_name character varying(255),
    logo_url text,
    contact_email character varying(255),
    contact_phone character varying(50),
    address text,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: white_label_brands; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.white_label_brands (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    partner_id uuid NOT NULL,
    brand_name character varying(255) NOT NULL,
    logo_url text,
    primary_color character varying(20),
    secondary_color character varying(20),
    support_email character varying(255),
    support_phone character varying(50),
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: workflow_actions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workflow_actions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    instance_id uuid NOT NULL,
    action_by uuid NOT NULL,
    action_type character varying(30),
    comments text,
    action_date timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: workflow_definitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workflow_definitions (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    school_id uuid NOT NULL,
    workflow_name character varying(255) NOT NULL,
    entity_type character varying(100) NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: workflow_instances; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workflow_instances (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    workflow_id uuid NOT NULL,
    entity_id uuid NOT NULL,
    entity_type character varying(100) NOT NULL,
    current_step integer,
    status character varying(30) DEFAULT 'PENDING'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: workflow_steps; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workflow_steps (
    id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    workflow_id uuid NOT NULL,
    step_order integer NOT NULL,
    role_id uuid NOT NULL,
    step_name character varying(255)
);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: school_calendar calendar_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_calendar ALTER COLUMN calendar_id SET DEFAULT nextval('public.school_calendar_calendar_id_seq'::regclass);


--
-- Name: academic_weeks academic_weeks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.academic_weeks
    ADD CONSTRAINT academic_weeks_pkey PRIMARY KEY (id);


--
-- Name: academic_weeks academic_weeks_term_id_week_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.academic_weeks
    ADD CONSTRAINT academic_weeks_term_id_week_number_unique UNIQUE (term_id, week_number);


--
-- Name: academic_years academic_years_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.academic_years
    ADD CONSTRAINT academic_years_pkey PRIMARY KEY (id);


--
-- Name: ai_assistants ai_assistants_assistant_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_assistants
    ADD CONSTRAINT ai_assistants_assistant_code_key UNIQUE (assistant_code);


--
-- Name: ai_assistants ai_assistants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_assistants
    ADD CONSTRAINT ai_assistants_pkey PRIMARY KEY (id);


--
-- Name: ai_conversations ai_conversations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_conversations
    ADD CONSTRAINT ai_conversations_pkey PRIMARY KEY (id);


--
-- Name: ai_generated_content ai_generated_content_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_generated_content
    ADD CONSTRAINT ai_generated_content_pkey PRIMARY KEY (id);


--
-- Name: ai_insights ai_insights_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_insights
    ADD CONSTRAINT ai_insights_pkey PRIMARY KEY (id);


--
-- Name: ai_prompts ai_prompts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_prompts
    ADD CONSTRAINT ai_prompts_pkey PRIMARY KEY (id);


--
-- Name: analytics_dimensions analytics_dimensions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.analytics_dimensions
    ADD CONSTRAINT analytics_dimensions_pkey PRIMARY KEY (id);


--
-- Name: api_access_logs api_access_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_access_logs
    ADD CONSTRAINT api_access_logs_pkey PRIMARY KEY (id);


--
-- Name: api_clients api_clients_api_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_clients
    ADD CONSTRAINT api_clients_api_key_key UNIQUE (api_key);


--
-- Name: api_clients api_clients_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_clients
    ADD CONSTRAINT api_clients_pkey PRIMARY KEY (id);


--
-- Name: api_tokens api_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_tokens
    ADD CONSTRAINT api_tokens_pkey PRIMARY KEY (id);


--
-- Name: approvals approvals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.approvals
    ADD CONSTRAINT approvals_pkey PRIMARY KEY (id);


--
-- Name: assessment_registrations assessment_registrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.assessment_registrations
    ADD CONSTRAINT assessment_registrations_pkey PRIMARY KEY (id);


--
-- Name: assessment_types assessment_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.assessment_types
    ADD CONSTRAINT assessment_types_pkey PRIMARY KEY (id);


--
-- Name: attendance_alerts attendance_alerts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_alerts
    ADD CONSTRAINT attendance_alerts_pkey PRIMARY KEY (id);


--
-- Name: attendance_sessions attendance_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_sessions
    ADD CONSTRAINT attendance_sessions_pkey PRIMARY KEY (id);


--
-- Name: attendance_statuses attendance_statuses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_statuses
    ADD CONSTRAINT attendance_statuses_pkey PRIMARY KEY (id);


--
-- Name: attendance_statuses attendance_statuses_status_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_statuses
    ADD CONSTRAINT attendance_statuses_status_code_key UNIQUE (status_code);


--
-- Name: attendance_statuses attendance_statuses_status_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_statuses
    ADD CONSTRAINT attendance_statuses_status_name_key UNIQUE (status_name);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (id);


--
-- Name: backup_files backup_files_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backup_files
    ADD CONSTRAINT backup_files_pkey PRIMARY KEY (id);


--
-- Name: backup_jobs backup_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backup_jobs
    ADD CONSTRAINT backup_jobs_pkey PRIMARY KEY (id);


--
-- Name: backup_policies backup_policies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backup_policies
    ADD CONSTRAINT backup_policies_pkey PRIMARY KEY (id);


--
-- Name: bed_allocations bed_allocations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bed_allocations
    ADD CONSTRAINT bed_allocations_pkey PRIMARY KEY (id);


--
-- Name: benchmark_groups benchmark_groups_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.benchmark_groups
    ADD CONSTRAINT benchmark_groups_pkey PRIMARY KEY (id);


--
-- Name: benchmark_metrics benchmark_metrics_metric_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.benchmark_metrics
    ADD CONSTRAINT benchmark_metrics_metric_code_key UNIQUE (metric_code);


--
-- Name: benchmark_metrics benchmark_metrics_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.benchmark_metrics
    ADD CONSTRAINT benchmark_metrics_pkey PRIMARY KEY (id);


--
-- Name: book_categories book_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.book_categories
    ADD CONSTRAINT book_categories_pkey PRIMARY KEY (id);


--
-- Name: book_copies book_copies_accession_number_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.book_copies
    ADD CONSTRAINT book_copies_accession_number_key UNIQUE (accession_number);


--
-- Name: book_copies book_copies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.book_copies
    ADD CONSTRAINT book_copies_pkey PRIMARY KEY (id);


--
-- Name: book_issues book_issues_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.book_issues
    ADD CONSTRAINT book_issues_pkey PRIMARY KEY (id);


--
-- Name: book_returns book_returns_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.book_returns
    ADD CONSTRAINT book_returns_pkey PRIMARY KEY (id);


--
-- Name: books books_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.books
    ADD CONSTRAINT books_pkey PRIMARY KEY (id);


--
-- Name: brand_domains brand_domains_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brand_domains
    ADD CONSTRAINT brand_domains_pkey PRIMARY KEY (id);


--
-- Name: brand_packages brand_packages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brand_packages
    ADD CONSTRAINT brand_packages_pkey PRIMARY KEY (id);


--
-- Name: brand_schools brand_schools_brand_id_school_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brand_schools
    ADD CONSTRAINT brand_schools_brand_id_school_id_key UNIQUE (brand_id, school_id);


--
-- Name: brand_schools brand_schools_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brand_schools
    ADD CONSTRAINT brand_schools_pkey PRIMARY KEY (id);


--
-- Name: broadcasts broadcasts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcasts
    ADD CONSTRAINT broadcasts_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: class_teachers class_teachers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.class_teachers
    ADD CONSTRAINT class_teachers_pkey PRIMARY KEY (id);


--
-- Name: comment_bank comment_bank_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comment_bank
    ADD CONSTRAINT comment_bank_pkey PRIMARY KEY (id);


--
-- Name: communication_channels communication_channels_channel_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.communication_channels
    ADD CONSTRAINT communication_channels_channel_code_key UNIQUE (channel_code);


--
-- Name: communication_channels communication_channels_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.communication_channels
    ADD CONSTRAINT communication_channels_pkey PRIMARY KEY (id);


--
-- Name: communication_logs communication_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.communication_logs
    ADD CONSTRAINT communication_logs_pkey PRIMARY KEY (id);


--
-- Name: communication_templates communication_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.communication_templates
    ADD CONSTRAINT communication_templates_pkey PRIMARY KEY (id);


--
-- Name: curriculum_coverage coverage_record_of_work_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.curriculum_coverage
    ADD CONSTRAINT coverage_record_of_work_unique UNIQUE (record_of_work_id);


--
-- Name: curriculum_coverage curriculum_coverage_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.curriculum_coverage
    ADD CONSTRAINT curriculum_coverage_pkey PRIMARY KEY (id);


--
-- Name: dashboard_preferences dashboard_preferences_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_preferences
    ADD CONSTRAINT dashboard_preferences_pkey PRIMARY KEY (id);


--
-- Name: dashboard_widgets dashboard_widgets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_widgets
    ADD CONSTRAINT dashboard_widgets_pkey PRIMARY KEY (id);


--
-- Name: discipline_actions discipline_actions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.discipline_actions
    ADD CONSTRAINT discipline_actions_pkey PRIMARY KEY (id);


--
-- Name: discipline_cases discipline_cases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.discipline_cases
    ADD CONSTRAINT discipline_cases_pkey PRIMARY KEY (id);


--
-- Name: discipline_categories discipline_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.discipline_categories
    ADD CONSTRAINT discipline_categories_pkey PRIMARY KEY (id);


--
-- Name: document_categories document_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_categories
    ADD CONSTRAINT document_categories_pkey PRIMARY KEY (id);


--
-- Name: documents documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_pkey PRIMARY KEY (id);


--
-- Name: education_levels education_levels_level_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.education_levels
    ADD CONSTRAINT education_levels_level_name_key UNIQUE (level_name);


--
-- Name: education_levels education_levels_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.education_levels
    ADD CONSTRAINT education_levels_pkey PRIMARY KEY (id);


--
-- Name: exam_invigilators exam_invigilators_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_invigilators
    ADD CONSTRAINT exam_invigilators_pkey PRIMARY KEY (id);


--
-- Name: exam_learning_areas exam_learning_areas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_learning_areas
    ADD CONSTRAINT exam_learning_areas_pkey PRIMARY KEY (id);


--
-- Name: exam_papers exam_papers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_papers
    ADD CONSTRAINT exam_papers_pkey PRIMARY KEY (id);


--
-- Name: exam_results exam_results_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_results
    ADD CONSTRAINT exam_results_pkey PRIMARY KEY (id);


--
-- Name: exams exams_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exams
    ADD CONSTRAINT exams_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: fee_arrears fee_arrears_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_arrears
    ADD CONSTRAINT fee_arrears_pkey PRIMARY KEY (id);


--
-- Name: fee_categories fee_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_categories
    ADD CONSTRAINT fee_categories_pkey PRIMARY KEY (id);


--
-- Name: fee_discounts fee_discounts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_discounts
    ADD CONSTRAINT fee_discounts_pkey PRIMARY KEY (id);


--
-- Name: fee_invoice_items fee_invoice_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_invoice_items
    ADD CONSTRAINT fee_invoice_items_pkey PRIMARY KEY (id);


--
-- Name: fee_invoices fee_invoices_invoice_number_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_invoices
    ADD CONSTRAINT fee_invoices_invoice_number_key UNIQUE (invoice_number);


--
-- Name: fee_invoices fee_invoices_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_invoices
    ADD CONSTRAINT fee_invoices_pkey PRIMARY KEY (id);


--
-- Name: fee_refunds fee_refunds_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_refunds
    ADD CONSTRAINT fee_refunds_pkey PRIMARY KEY (id);


--
-- Name: fee_structures fee_structures_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_structures
    ADD CONSTRAINT fee_structures_pkey PRIMARY KEY (id);


--
-- Name: fee_transaction_types fee_transaction_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_transaction_types
    ADD CONSTRAINT fee_transaction_types_pkey PRIMARY KEY (id);


--
-- Name: fee_transaction_types fee_transaction_types_transaction_type_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_transaction_types
    ADD CONSTRAINT fee_transaction_types_transaction_type_key UNIQUE (transaction_type);


--
-- Name: finance_adjustments finance_adjustments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.finance_adjustments
    ADD CONSTRAINT finance_adjustments_pkey PRIMARY KEY (id);


--
-- Name: finance_audit_logs finance_audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.finance_audit_logs
    ADD CONSTRAINT finance_audit_logs_pkey PRIMARY KEY (id);


--
-- Name: finance_settings finance_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.finance_settings
    ADD CONSTRAINT finance_settings_pkey PRIMARY KEY (id);


--
-- Name: finance_settings finance_settings_school_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.finance_settings
    ADD CONSTRAINT finance_settings_school_id_key UNIQUE (school_id);


--
-- Name: grades grades_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grades
    ADD CONSTRAINT grades_pkey PRIMARY KEY (id);


--
-- Name: grading_scales grading_scales_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grading_scales
    ADD CONSTRAINT grading_scales_pkey PRIMARY KEY (id);


--
-- Name: grading_systems grading_systems_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grading_systems
    ADD CONSTRAINT grading_systems_pkey PRIMARY KEY (id);


--
-- Name: group_announcements group_announcements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.group_announcements
    ADD CONSTRAINT group_announcements_pkey PRIMARY KEY (id);


--
-- Name: group_targets group_targets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.group_targets
    ADD CONSTRAINT group_targets_pkey PRIMARY KEY (id);


--
-- Name: group_users group_users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.group_users
    ADD CONSTRAINT group_users_pkey PRIMARY KEY (id);


--
-- Name: guidance_counselling_records guidance_counselling_records_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guidance_counselling_records
    ADD CONSTRAINT guidance_counselling_records_pkey PRIMARY KEY (id);


--
-- Name: hod_assignments hod_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hod_assignments
    ADD CONSTRAINT hod_assignments_pkey PRIMARY KEY (id);


--
-- Name: hostel_attendance hostel_attendance_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hostel_attendance
    ADD CONSTRAINT hostel_attendance_pkey PRIMARY KEY (id);


--
-- Name: hostel_beds hostel_beds_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hostel_beds
    ADD CONSTRAINT hostel_beds_pkey PRIMARY KEY (id);


--
-- Name: hostel_incidents hostel_incidents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hostel_incidents
    ADD CONSTRAINT hostel_incidents_pkey PRIMARY KEY (id);


--
-- Name: hostel_rooms hostel_rooms_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hostel_rooms
    ADD CONSTRAINT hostel_rooms_pkey PRIMARY KEY (id);


--
-- Name: hostel_staff_assignments hostel_staff_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hostel_staff_assignments
    ADD CONSTRAINT hostel_staff_assignments_pkey PRIMARY KEY (id);


--
-- Name: hostels hostels_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hostels
    ADD CONSTRAINT hostels_pkey PRIMARY KEY (id);


--
-- Name: integration_providers integration_providers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.integration_providers
    ADD CONSTRAINT integration_providers_pkey PRIMARY KEY (id);


--
-- Name: inventory_categories inventory_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_categories
    ADD CONSTRAINT inventory_categories_pkey PRIMARY KEY (id);


--
-- Name: inventory_items inventory_items_item_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_items
    ADD CONSTRAINT inventory_items_item_code_key UNIQUE (item_code);


--
-- Name: inventory_items inventory_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_items
    ADD CONSTRAINT inventory_items_pkey PRIMARY KEY (id);


--
-- Name: inventory_transactions inventory_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_transactions
    ADD CONSTRAINT inventory_transactions_pkey PRIMARY KEY (id);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: learner_attendance learner_attendance_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_attendance
    ADD CONSTRAINT learner_attendance_pkey PRIMARY KEY (id);


--
-- Name: learner_discounts learner_discounts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_discounts
    ADD CONSTRAINT learner_discounts_pkey PRIMARY KEY (id);


--
-- Name: learner_documents learner_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_documents
    ADD CONSTRAINT learner_documents_pkey PRIMARY KEY (id);


--
-- Name: learner_fee_accounts learner_fee_accounts_account_number_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_fee_accounts
    ADD CONSTRAINT learner_fee_accounts_account_number_key UNIQUE (account_number);


--
-- Name: learner_fee_accounts learner_fee_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_fee_accounts
    ADD CONSTRAINT learner_fee_accounts_pkey PRIMARY KEY (id);


--
-- Name: learner_fee_ledger learner_fee_ledger_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_fee_ledger
    ADD CONSTRAINT learner_fee_ledger_pkey PRIMARY KEY (id);


--
-- Name: learner_medical_profiles learner_medical_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_medical_profiles
    ADD CONSTRAINT learner_medical_profiles_pkey PRIMARY KEY (id);


--
-- Name: learner_parents learner_parents_learner_id_parent_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_parents
    ADD CONSTRAINT learner_parents_learner_id_parent_id_unique UNIQUE (learner_id, parent_id);


--
-- Name: learner_parents learner_parents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_parents
    ADD CONSTRAINT learner_parents_pkey PRIMARY KEY (id);


--
-- Name: learner_transport_assignments learner_transport_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_transport_assignments
    ADD CONSTRAINT learner_transport_assignments_pkey PRIMARY KEY (id);


--
-- Name: learners learners_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learners
    ADD CONSTRAINT learners_pkey PRIMARY KEY (id);


--
-- Name: learning_area_allocations learning_area_allocations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_area_allocations
    ADD CONSTRAINT learning_area_allocations_pkey PRIMARY KEY (id);


--
-- Name: learning_area_analysis learning_area_analysis_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_area_analysis
    ADD CONSTRAINT learning_area_analysis_pkey PRIMARY KEY (id);


--
-- Name: learning_area_constraints learning_area_constraints_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_area_constraints
    ADD CONSTRAINT learning_area_constraints_pkey PRIMARY KEY (id);


--
-- Name: learning_area_relationships learning_area_relationships_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_area_relationships
    ADD CONSTRAINT learning_area_relationships_pkey PRIMARY KEY (id);


--
-- Name: learning_areas learning_areas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_areas
    ADD CONSTRAINT learning_areas_pkey PRIMARY KEY (id);


--
-- Name: lesson_notes lesson_notes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lesson_notes
    ADD CONSTRAINT lesson_notes_pkey PRIMARY KEY (id);


--
-- Name: lesson_notes lesson_notes_plan_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lesson_notes
    ADD CONSTRAINT lesson_notes_plan_unique UNIQUE (lesson_plan_id);


--
-- Name: lesson_plans lesson_plans_assignment_lesson_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lesson_plans
    ADD CONSTRAINT lesson_plans_assignment_lesson_unique UNIQUE (teacher_assignment_id, scheme_lesson_id);


--
-- Name: lesson_plans lesson_plans_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lesson_plans
    ADD CONSTRAINT lesson_plans_pkey PRIMARY KEY (id);


--
-- Name: level_learning_areas level_learning_areas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.level_learning_areas
    ADD CONSTRAINT level_learning_areas_pkey PRIMARY KEY (id);


--
-- Name: library_fines library_fines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.library_fines
    ADD CONSTRAINT library_fines_pkey PRIMARY KEY (id);


--
-- Name: license_partners license_partners_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.license_partners
    ADD CONSTRAINT license_partners_pkey PRIMARY KEY (id);


--
-- Name: mark_entry_permissions mark_entry_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mark_entry_permissions
    ADD CONSTRAINT mark_entry_permissions_pkey PRIMARY KEY (id);


--
-- Name: marketplace_categories marketplace_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketplace_categories
    ADD CONSTRAINT marketplace_categories_pkey PRIMARY KEY (id);


--
-- Name: marketplace_modules marketplace_modules_module_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketplace_modules
    ADD CONSTRAINT marketplace_modules_module_code_key UNIQUE (module_code);


--
-- Name: marketplace_modules marketplace_modules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketplace_modules
    ADD CONSTRAINT marketplace_modules_pkey PRIMARY KEY (id);


--
-- Name: medical_conditions medical_conditions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medical_conditions
    ADD CONSTRAINT medical_conditions_pkey PRIMARY KEY (id);


--
-- Name: medical_referrals medical_referrals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medical_referrals
    ADD CONSTRAINT medical_referrals_pkey PRIMARY KEY (id);


--
-- Name: medication_administration medication_administration_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medication_administration
    ADD CONSTRAINT medication_administration_pkey PRIMARY KEY (id);


--
-- Name: medications medications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medications
    ADD CONSTRAINT medications_pkey PRIMARY KEY (id);


--
-- Name: merit_lists merit_lists_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.merit_lists
    ADD CONSTRAINT merit_lists_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: module_installations module_installations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.module_installations
    ADD CONSTRAINT module_installations_pkey PRIMARY KEY (id);


--
-- Name: module_versions module_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.module_versions
    ADD CONSTRAINT module_versions_pkey PRIMARY KEY (id);


--
-- Name: mpesa_callback_logs mpesa_callback_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mpesa_callback_logs
    ADD CONSTRAINT mpesa_callback_logs_pkey PRIMARY KEY (id);


--
-- Name: mpesa_transactions mpesa_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mpesa_transactions
    ADD CONSTRAINT mpesa_transactions_pkey PRIMARY KEY (id);


--
-- Name: notification_preferences notification_preferences_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_preferences
    ADD CONSTRAINT notification_preferences_pkey PRIMARY KEY (id);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: online_applications online_applications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.online_applications
    ADD CONSTRAINT online_applications_pkey PRIMARY KEY (id);


--
-- Name: package_features package_features_package_id_feature_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.package_features
    ADD CONSTRAINT package_features_package_id_feature_id_key UNIQUE (package_id, feature_id);


--
-- Name: package_features package_features_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.package_features
    ADD CONSTRAINT package_features_pkey PRIMARY KEY (id);


--
-- Name: parent_learner_links parent_learner_links_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.parent_learner_links
    ADD CONSTRAINT parent_learner_links_pkey PRIMARY KEY (id);


--
-- Name: parent_meetings parent_meetings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.parent_meetings
    ADD CONSTRAINT parent_meetings_pkey PRIMARY KEY (id);


--
-- Name: parents parents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.parents
    ADD CONSTRAINT parents_pkey PRIMARY KEY (id);


--
-- Name: parents parents_user_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.parents
    ADD CONSTRAINT parents_user_id_key UNIQUE (user_id);


--
-- Name: pathway_recommendations pathway_recommendations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pathway_recommendations
    ADD CONSTRAINT pathway_recommendations_pkey PRIMARY KEY (id);


--
-- Name: payment_allocations payment_allocations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_allocations
    ADD CONSTRAINT payment_allocations_pkey PRIMARY KEY (id);


--
-- Name: payment_methods payment_methods_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_methods
    ADD CONSTRAINT payment_methods_pkey PRIMARY KEY (id);


--
-- Name: payment_plan_installments payment_plan_installments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_plan_installments
    ADD CONSTRAINT payment_plan_installments_pkey PRIMARY KEY (id);


--
-- Name: payment_plans payment_plans_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_plans
    ADD CONSTRAINT payment_plans_pkey PRIMARY KEY (id);


--
-- Name: payments payments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_pkey PRIMARY KEY (id);


--
-- Name: payments payments_receipt_number_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_receipt_number_key UNIQUE (receipt_number);


--
-- Name: permissions permissions_permission_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_permission_name_key UNIQUE (permission_name);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: platform_audit_logs platform_audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.platform_audit_logs
    ADD CONSTRAINT platform_audit_logs_pkey PRIMARY KEY (id);


--
-- Name: records_of_work records_of_work_lesson_plan_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.records_of_work
    ADD CONSTRAINT records_of_work_lesson_plan_unique UNIQUE (lesson_plan_id);


--
-- Name: records_of_work records_of_work_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.records_of_work
    ADD CONSTRAINT records_of_work_pkey PRIMARY KEY (id);


--
-- Name: reference_types reference_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reference_types
    ADD CONSTRAINT reference_types_pkey PRIMARY KEY (id);


--
-- Name: reference_types reference_types_reference_type_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reference_types
    ADD CONSTRAINT reference_types_reference_type_key UNIQUE (reference_type);


--
-- Name: report_card_learning_areas report_card_learning_areas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_card_learning_areas
    ADD CONSTRAINT report_card_learning_areas_pkey PRIMARY KEY (id);


--
-- Name: report_cards report_cards_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_cards
    ADD CONSTRAINT report_cards_pkey PRIMARY KEY (id);


--
-- Name: report_templates report_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_templates
    ADD CONSTRAINT report_templates_pkey PRIMARY KEY (id);


--
-- Name: restore_logs restore_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restore_logs
    ADD CONSTRAINT restore_logs_pkey PRIMARY KEY (id);


--
-- Name: restore_requests restore_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restore_requests
    ADD CONSTRAINT restore_requests_pkey PRIMARY KEY (id);


--
-- Name: role_permissions role_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_pkey PRIMARY KEY (id);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: roles roles_role_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_role_name_key UNIQUE (role_name);


--
-- Name: room_constraints room_constraints_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.room_constraints
    ADD CONSTRAINT room_constraints_pkey PRIMARY KEY (id);


--
-- Name: room_types room_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.room_types
    ADD CONSTRAINT room_types_pkey PRIMARY KEY (id);


--
-- Name: room_types room_types_type_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.room_types
    ADD CONSTRAINT room_types_type_name_key UNIQUE (type_name);


--
-- Name: rooms rooms_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rooms
    ADD CONSTRAINT rooms_pkey PRIMARY KEY (id);


--
-- Name: route_stops route_stops_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.route_stops
    ADD CONSTRAINT route_stops_pkey PRIMARY KEY (id);


--
-- Name: saved_reports saved_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.saved_reports
    ADD CONSTRAINT saved_reports_pkey PRIMARY KEY (id);


--
-- Name: scheme_lessons scheme_lessons_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scheme_lessons
    ADD CONSTRAINT scheme_lessons_pkey PRIMARY KEY (id);


--
-- Name: scheme_lessons scheme_lessons_scheme_lesson_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scheme_lessons
    ADD CONSTRAINT scheme_lessons_scheme_lesson_unique UNIQUE (scheme_id, lesson_number);


--
-- Name: scheme_weeks scheme_weeks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scheme_weeks
    ADD CONSTRAINT scheme_weeks_pkey PRIMARY KEY (id);


--
-- Name: schemes_of_work schemes_of_work_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schemes_of_work
    ADD CONSTRAINT schemes_of_work_pkey PRIMARY KEY (id);


--
-- Name: school_benchmarks school_benchmarks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_benchmarks
    ADD CONSTRAINT school_benchmarks_pkey PRIMARY KEY (id);


--
-- Name: school_calendar school_calendar_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_calendar
    ADD CONSTRAINT school_calendar_pkey PRIMARY KEY (calendar_id);


--
-- Name: school_group_members school_group_members_group_id_school_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_group_members
    ADD CONSTRAINT school_group_members_group_id_school_id_key UNIQUE (group_id, school_id);


--
-- Name: school_group_members school_group_members_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_group_members
    ADD CONSTRAINT school_group_members_pkey PRIMARY KEY (id);


--
-- Name: school_groups school_groups_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_groups
    ADD CONSTRAINT school_groups_pkey PRIMARY KEY (id);


--
-- Name: school_integrations school_integrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_integrations
    ADD CONSTRAINT school_integrations_pkey PRIMARY KEY (id);


--
-- Name: school_learning_areas school_learning_areas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_learning_areas
    ADD CONSTRAINT school_learning_areas_pkey PRIMARY KEY (id);


--
-- Name: school_module_subscriptions school_module_subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_module_subscriptions
    ADD CONSTRAINT school_module_subscriptions_pkey PRIMARY KEY (id);


--
-- Name: school_module_subscriptions school_module_subscriptions_school_id_module_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_module_subscriptions
    ADD CONSTRAINT school_module_subscriptions_school_id_module_id_key UNIQUE (school_id, module_id);


--
-- Name: school_payment_gateways school_payment_gateways_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_payment_gateways
    ADD CONSTRAINT school_payment_gateways_pkey PRIMARY KEY (id);


--
-- Name: school_settings school_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_settings
    ADD CONSTRAINT school_settings_pkey PRIMARY KEY (id);


--
-- Name: school_settings school_settings_school_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_settings
    ADD CONSTRAINT school_settings_school_id_key UNIQUE (school_id);


--
-- Name: school_status_logs school_status_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_status_logs
    ADD CONSTRAINT school_status_logs_pkey PRIMARY KEY (id);


--
-- Name: school_subscriptions school_subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_subscriptions
    ADD CONSTRAINT school_subscriptions_pkey PRIMARY KEY (id);


--
-- Name: schools schools_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schools
    ADD CONSTRAINT schools_pkey PRIMARY KEY (id);


--
-- Name: schools schools_school_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schools
    ADD CONSTRAINT schools_school_code_key UNIQUE (school_code);


--
-- Name: sick_bay_visits sick_bay_visits_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sick_bay_visits
    ADD CONSTRAINT sick_bay_visits_pkey PRIMARY KEY (id);


--
-- Name: sms_credit_purchases sms_credit_purchases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sms_credit_purchases
    ADD CONSTRAINT sms_credit_purchases_pkey PRIMARY KEY (id);


--
-- Name: sms_packages sms_packages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sms_packages
    ADD CONSTRAINT sms_packages_pkey PRIMARY KEY (id);


--
-- Name: sms_transactions sms_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sms_transactions
    ADD CONSTRAINT sms_transactions_pkey PRIMARY KEY (id);


--
-- Name: sms_wallets sms_wallets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sms_wallets
    ADD CONSTRAINT sms_wallets_pkey PRIMARY KEY (id);


--
-- Name: sms_wallets sms_wallets_school_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sms_wallets
    ADD CONSTRAINT sms_wallets_school_id_key UNIQUE (school_id);


--
-- Name: staff_documents staff_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staff_documents
    ADD CONSTRAINT staff_documents_pkey PRIMARY KEY (id);


--
-- Name: stock_adjustments stock_adjustments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_adjustments
    ADD CONSTRAINT stock_adjustments_pkey PRIMARY KEY (id);


--
-- Name: stock_issue_items stock_issue_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_issue_items
    ADD CONSTRAINT stock_issue_items_pkey PRIMARY KEY (id);


--
-- Name: stock_issues stock_issues_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_issues
    ADD CONSTRAINT stock_issues_pkey PRIMARY KEY (id);


--
-- Name: stock_receipt_items stock_receipt_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_receipt_items
    ADD CONSTRAINT stock_receipt_items_pkey PRIMARY KEY (id);


--
-- Name: stock_receipts stock_receipts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_receipts
    ADD CONSTRAINT stock_receipts_pkey PRIMARY KEY (id);


--
-- Name: streams streams_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.streams
    ADD CONSTRAINT streams_pkey PRIMARY KEY (id);


--
-- Name: subscription_features subscription_features_feature_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_features
    ADD CONSTRAINT subscription_features_feature_code_key UNIQUE (feature_code);


--
-- Name: subscription_features subscription_features_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_features
    ADD CONSTRAINT subscription_features_pkey PRIMARY KEY (id);


--
-- Name: subscription_packages subscription_packages_package_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_packages
    ADD CONSTRAINT subscription_packages_package_name_key UNIQUE (package_name);


--
-- Name: subscription_packages subscription_packages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_packages
    ADD CONSTRAINT subscription_packages_pkey PRIMARY KEY (id);


--
-- Name: subscription_payment_requests subscription_payment_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_payment_requests
    ADD CONSTRAINT subscription_payment_requests_pkey PRIMARY KEY (id);


--
-- Name: subscription_payments subscription_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_payments
    ADD CONSTRAINT subscription_payments_pkey PRIMARY KEY (id);


--
-- Name: subscription_upgrade_requests subscription_upgrade_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_upgrade_requests
    ADD CONSTRAINT subscription_upgrade_requests_pkey PRIMARY KEY (id);


--
-- Name: super_admins super_admins_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.super_admins
    ADD CONSTRAINT super_admins_pkey PRIMARY KEY (id);


--
-- Name: super_admins super_admins_user_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.super_admins
    ADD CONSTRAINT super_admins_user_id_key UNIQUE (user_id);


--
-- Name: suppliers suppliers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_pkey PRIMARY KEY (id);


--
-- Name: support_ticket_comments support_ticket_comments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.support_ticket_comments
    ADD CONSTRAINT support_ticket_comments_pkey PRIMARY KEY (id);


--
-- Name: support_tickets support_tickets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT support_tickets_pkey PRIMARY KEY (id);


--
-- Name: support_tickets support_tickets_ticket_number_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT support_tickets_ticket_number_key UNIQUE (ticket_number);


--
-- Name: system_announcements system_announcements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_announcements
    ADD CONSTRAINT system_announcements_pkey PRIMARY KEY (id);


--
-- Name: system_settings system_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_settings
    ADD CONSTRAINT system_settings_pkey PRIMARY KEY (id);


--
-- Name: system_settings system_settings_setting_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_settings
    ADD CONSTRAINT system_settings_setting_key_key UNIQUE (setting_key);


--
-- Name: teacher_assignments teacher_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teacher_assignments
    ADD CONSTRAINT teacher_assignments_pkey PRIMARY KEY (id);


--
-- Name: teacher_availability teacher_availability_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teacher_availability
    ADD CONSTRAINT teacher_availability_pkey PRIMARY KEY (id);


--
-- Name: teacher_constraints teacher_constraints_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teacher_constraints
    ADD CONSTRAINT teacher_constraints_pkey PRIMARY KEY (id);


--
-- Name: teachers teachers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teachers
    ADD CONSTRAINT teachers_pkey PRIMARY KEY (id);


--
-- Name: teachers teachers_tsc_no_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teachers
    ADD CONSTRAINT teachers_tsc_no_key UNIQUE (tsc_no);


--
-- Name: teachers teachers_user_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teachers
    ADD CONSTRAINT teachers_user_id_key UNIQUE (user_id);


--
-- Name: terms terms_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.terms
    ADD CONSTRAINT terms_pkey PRIMARY KEY (id);


--
-- Name: timetable_conflicts timetable_conflicts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_conflicts
    ADD CONSTRAINT timetable_conflicts_pkey PRIMARY KEY (id);


--
-- Name: timetable_constraints timetable_constraints_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_constraints
    ADD CONSTRAINT timetable_constraints_pkey PRIMARY KEY (id);


--
-- Name: timetable_entries timetable_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_entries
    ADD CONSTRAINT timetable_entries_pkey PRIMARY KEY (id);


--
-- Name: timetable_generation_runs timetable_generation_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_generation_runs
    ADD CONSTRAINT timetable_generation_runs_pkey PRIMARY KEY (id);


--
-- Name: timetable_periods timetable_periods_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_periods
    ADD CONSTRAINT timetable_periods_pkey PRIMARY KEY (id);


--
-- Name: timetable_profiles timetable_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_profiles
    ADD CONSTRAINT timetable_profiles_pkey PRIMARY KEY (id);


--
-- Name: timetable_publications timetable_publications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_publications
    ADD CONSTRAINT timetable_publications_pkey PRIMARY KEY (id);


--
-- Name: timetable_substitutions timetable_substitutions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_substitutions
    ADD CONSTRAINT timetable_substitutions_pkey PRIMARY KEY (id);


--
-- Name: timetables timetables_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetables
    ADD CONSTRAINT timetables_pkey PRIMARY KEY (id);


--
-- Name: transaction_reconciliations transaction_reconciliations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaction_reconciliations
    ADD CONSTRAINT transaction_reconciliations_pkey PRIMARY KEY (id);


--
-- Name: transport_attendance transport_attendance_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transport_attendance
    ADD CONSTRAINT transport_attendance_pkey PRIMARY KEY (id);


--
-- Name: transport_incidents transport_incidents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transport_incidents
    ADD CONSTRAINT transport_incidents_pkey PRIMARY KEY (id);


--
-- Name: transport_routes transport_routes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transport_routes
    ADD CONSTRAINT transport_routes_pkey PRIMARY KEY (id);


--
-- Name: learning_area_allocations uq_grade_learning_area; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_area_allocations
    ADD CONSTRAINT uq_grade_learning_area UNIQUE (grade_id, learning_area_id);


--
-- Name: learner_attendance uq_learner_attendance; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_attendance
    ADD CONSTRAINT uq_learner_attendance UNIQUE (learner_id, attendance_date, attendance_session_id);


--
-- Name: learner_documents uq_learner_document; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_documents
    ADD CONSTRAINT uq_learner_document UNIQUE (learner_id, document_id, document_number);


--
-- Name: rooms uq_room_code; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rooms
    ADD CONSTRAINT uq_room_code UNIQUE (school_id, room_code);


--
-- Name: rooms uq_room_name; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rooms
    ADD CONSTRAINT uq_room_name UNIQUE (school_id, room_name);


--
-- Name: room_types uq_room_type_name; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.room_types
    ADD CONSTRAINT uq_room_type_name UNIQUE (type_name);


--
-- Name: staff_documents uq_staff_document; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staff_documents
    ADD CONSTRAINT uq_staff_document UNIQUE (teacher_id, document_id, document_number);


--
-- Name: teacher_availability uq_teacher_availability; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teacher_availability
    ADD CONSTRAINT uq_teacher_availability UNIQUE (teacher_id, day_of_week, period_id);


--
-- Name: teacher_availability uq_teacher_period; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teacher_availability
    ADD CONSTRAINT uq_teacher_period UNIQUE (teacher_id, day_of_week, period_id);


--
-- Name: timetable_entries uq_timetable_entry; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_entries
    ADD CONSTRAINT uq_timetable_entry UNIQUE (timetable_id, day_of_week, period_id, grade_id, stream_id);


--
-- Name: user_roles user_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_pkey PRIMARY KEY (user_id, role_id);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_username_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_username_key UNIQUE (username);


--
-- Name: vehicle_assignments vehicle_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_assignments
    ADD CONSTRAINT vehicle_assignments_pkey PRIMARY KEY (id);


--
-- Name: vehicles vehicles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicles
    ADD CONSTRAINT vehicles_pkey PRIMARY KEY (id);


--
-- Name: vehicles vehicles_registration_number_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicles
    ADD CONSTRAINT vehicles_registration_number_key UNIQUE (registration_number);


--
-- Name: website_events website_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.website_events
    ADD CONSTRAINT website_events_pkey PRIMARY KEY (id);


--
-- Name: website_galleries website_galleries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.website_galleries
    ADD CONSTRAINT website_galleries_pkey PRIMARY KEY (id);


--
-- Name: website_gallery_images website_gallery_images_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.website_gallery_images
    ADD CONSTRAINT website_gallery_images_pkey PRIMARY KEY (id);


--
-- Name: website_news website_news_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.website_news
    ADD CONSTRAINT website_news_pkey PRIMARY KEY (id);


--
-- Name: website_pages website_pages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.website_pages
    ADD CONSTRAINT website_pages_pkey PRIMARY KEY (id);


--
-- Name: website_settings website_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.website_settings
    ADD CONSTRAINT website_settings_pkey PRIMARY KEY (id);


--
-- Name: website_settings website_settings_school_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.website_settings
    ADD CONSTRAINT website_settings_school_id_key UNIQUE (school_id);


--
-- Name: white_label_brands white_label_brands_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.white_label_brands
    ADD CONSTRAINT white_label_brands_pkey PRIMARY KEY (id);


--
-- Name: workflow_actions workflow_actions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_actions
    ADD CONSTRAINT workflow_actions_pkey PRIMARY KEY (id);


--
-- Name: workflow_definitions workflow_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_definitions
    ADD CONSTRAINT workflow_definitions_pkey PRIMARY KEY (id);


--
-- Name: workflow_instances workflow_instances_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_instances
    ADD CONSTRAINT workflow_instances_pkey PRIMARY KEY (id);


--
-- Name: workflow_steps workflow_steps_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_steps
    ADD CONSTRAINT workflow_steps_pkey PRIMARY KEY (id);


--
-- Name: assessment_reg_learner_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX assessment_reg_learner_idx ON public.assessment_registrations USING btree (school_id, learner_id, assessment_year);


--
-- Name: assessment_reg_period_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX assessment_reg_period_idx ON public.assessment_registrations USING btree (school_id, assessment_year, assessment_type, status, is_deleted);


--
-- Name: assessment_types_tenant_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX assessment_types_tenant_idx ON public.assessment_types USING btree (school_id, active, is_deleted);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: coverage_assignment_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX coverage_assignment_idx ON public.curriculum_coverage USING btree (school_id, teacher_assignment_id, completed, is_deleted);


--
-- Name: exam_learning_areas_exam_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX exam_learning_areas_exam_idx ON public.exam_learning_areas USING btree (exam_id, is_deleted);


--
-- Name: exam_papers_area_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX exam_papers_area_idx ON public.exam_papers USING btree (exam_learning_area_id, is_deleted);


--
-- Name: exam_results_learner_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX exam_results_learner_idx ON public.exam_results USING btree (exam_id, learner_id, is_deleted);


--
-- Name: exam_results_paper_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX exam_results_paper_idx ON public.exam_results USING btree (paper_id, is_deleted);


--
-- Name: exams_period_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX exams_period_idx ON public.exams USING btree (school_id, academic_year_id, term_id, status, is_deleted);


--
-- Name: idx_assessment_registrations_school; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_assessment_registrations_school ON public.assessment_registrations USING btree (school_id);


--
-- Name: idx_attendance_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_attendance_date ON public.learner_attendance USING btree (attendance_date);


--
-- Name: idx_attendance_learner; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_attendance_learner ON public.learner_attendance USING btree (learner_id);


--
-- Name: idx_attendance_school; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_attendance_school ON public.learner_attendance USING btree (school_id);


--
-- Name: idx_attendance_session; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_attendance_session ON public.learner_attendance USING btree (attendance_session_id);


--
-- Name: idx_exam_results_exam; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_exam_results_exam ON public.exam_results USING btree (exam_id);


--
-- Name: idx_exam_results_learner; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_exam_results_learner ON public.exam_results USING btree (learner_id);


--
-- Name: idx_exam_results_learning_area; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_exam_results_learning_area ON public.exam_results USING btree (learning_area_id);


--
-- Name: idx_fee_ledger_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fee_ledger_date ON public.learner_fee_ledger USING btree (transaction_date);


--
-- Name: idx_fee_ledger_learner; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fee_ledger_learner ON public.learner_fee_ledger USING btree (learner_id);


--
-- Name: idx_fee_ledger_school; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fee_ledger_school ON public.learner_fee_ledger USING btree (school_id);


--
-- Name: idx_fee_ledger_term; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fee_ledger_term ON public.learner_fee_ledger USING btree (term_id);


--
-- Name: idx_fee_ledger_year; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fee_ledger_year ON public.learner_fee_ledger USING btree (academic_year_id);


--
-- Name: idx_grades_school; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_grades_school ON public.grades USING btree (school_id);


--
-- Name: idx_learner_documents_school; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_learner_documents_school ON public.learner_documents USING btree (school_id);


--
-- Name: idx_learners_admission; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_learners_admission ON public.learners USING btree (admission_no);


--
-- Name: idx_learners_grade; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_learners_grade ON public.learners USING btree (grade_id);


--
-- Name: idx_learners_name; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_learners_name ON public.learners USING btree (last_name, first_name);


--
-- Name: idx_learners_school; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_learners_school ON public.learners USING btree (school_id);


--
-- Name: idx_learners_stream; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_learners_stream ON public.learners USING btree (stream_id);


--
-- Name: idx_parents_phone; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_parents_phone ON public.parents USING btree (phone);


--
-- Name: idx_parents_school; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_parents_school ON public.parents USING btree (school_id);


--
-- Name: idx_payments_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payments_date ON public.payments USING btree (payment_date);


--
-- Name: idx_payments_learner; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payments_learner ON public.payments USING btree (learner_id);


--
-- Name: idx_payments_school; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_payments_school ON public.payments USING btree (school_id);


--
-- Name: idx_school_subscriptions_expiry; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_school_subscriptions_expiry ON public.school_subscriptions USING btree (expiry_date);


--
-- Name: idx_school_subscriptions_grace; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_school_subscriptions_grace ON public.school_subscriptions USING btree (grace_end_date);


--
-- Name: idx_school_subscriptions_package; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_school_subscriptions_package ON public.school_subscriptions USING btree (package_id);


--
-- Name: idx_school_subscriptions_school; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_school_subscriptions_school ON public.school_subscriptions USING btree (school_id);


--
-- Name: idx_school_subscriptions_school_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_school_subscriptions_school_status ON public.school_subscriptions USING btree (school_id, status);


--
-- Name: idx_school_subscriptions_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_school_subscriptions_status ON public.school_subscriptions USING btree (status);


--
-- Name: idx_spr_request_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_spr_request_date ON public.subscription_payment_requests USING btree (request_date);


--
-- Name: idx_spr_school; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_spr_school ON public.subscription_payment_requests USING btree (school_id);


--
-- Name: idx_spr_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_spr_status ON public.subscription_payment_requests USING btree (status);


--
-- Name: idx_staff_documents_school; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_staff_documents_school ON public.staff_documents USING btree (school_id);


--
-- Name: idx_streams_grade; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_streams_grade ON public.streams USING btree (grade_id);


--
-- Name: idx_streams_school; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_streams_school ON public.streams USING btree (school_id);


--
-- Name: idx_teacher_assignments_learning_area; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_teacher_assignments_learning_area ON public.teacher_assignments USING btree (learning_area_id);


--
-- Name: idx_teacher_assignments_school; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_teacher_assignments_school ON public.teacher_assignments USING btree (school_id);


--
-- Name: idx_teacher_assignments_teacher; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_teacher_assignments_teacher ON public.teacher_assignments USING btree (teacher_id);


--
-- Name: idx_teachers_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_teachers_active ON public.teachers USING btree (active);


--
-- Name: idx_teachers_school; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_teachers_school ON public.teachers USING btree (school_id);


--
-- Name: idx_timetable_entries_room; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_timetable_entries_room ON public.timetable_entries USING btree (room_id);


--
-- Name: idx_timetable_entries_teacher; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_timetable_entries_teacher ON public.timetable_entries USING btree (teacher_id);


--
-- Name: idx_timetable_entries_timetable; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_timetable_entries_timetable ON public.timetable_entries USING btree (timetable_id);


--
-- Name: idx_users_role; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_users_role ON public.users USING btree (role_id);


--
-- Name: idx_users_school; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_users_school ON public.users USING btree (school_id);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: lesson_notes_tenant_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX lesson_notes_tenant_idx ON public.lesson_notes USING btree (school_id, is_deleted, created_at);


--
-- Name: lesson_plans_school_date_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX lesson_plans_school_date_idx ON public.lesson_plans USING btree (school_id, lesson_date, status, is_deleted);


--
-- Name: mark_permissions_exam_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX mark_permissions_exam_idx ON public.mark_entry_permissions USING btree (exam_id, active, is_deleted);


--
-- Name: row_school_date_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX row_school_date_idx ON public.records_of_work USING btree (school_id, date_taught, status, is_deleted);


--
-- Name: scheme_lessons_week_current_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX scheme_lessons_week_current_idx ON public.scheme_lessons USING btree (week_id, is_deleted);


--
-- Name: sow_curriculum_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sow_curriculum_idx ON public.schemes_of_work USING btree (grade_id, learning_area_id, active, is_deleted);


--
-- Name: sow_school_period_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sow_school_period_idx ON public.schemes_of_work USING btree (school_id, academic_year_id, term_id);


--
-- Name: ta_class_teacher_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ta_class_teacher_idx ON public.teacher_assignments USING btree (stream_id, is_class_teacher, active, is_deleted);


--
-- Name: ta_school_period_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ta_school_period_idx ON public.teacher_assignments USING btree (school_id, academic_year_id, term_id);


--
-- Name: uq_attendance; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_attendance ON public.learner_attendance USING btree (learner_id, attendance_session_id, attendance_date);


--
-- Name: uq_exam_results; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_exam_results ON public.exam_results USING btree (exam_id, learner_id, learning_area_id, paper_id);


--
-- Name: uq_grade_school; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_grade_school ON public.grades USING btree (school_id, grade_name);


--
-- Name: uq_grades_school; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_grades_school ON public.grades USING btree (school_id, grade_name);


--
-- Name: uq_learners_school_admission; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_learners_school_admission ON public.learners USING btree (school_id, admission_no) WHERE (is_deleted = false);


--
-- Name: uq_stream_grade; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_stream_grade ON public.streams USING btree (grade_id, stream_name);


--
-- Name: uq_streams_grade; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_streams_grade ON public.streams USING btree (grade_id, stream_name);


--
-- Name: uq_users_email; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_users_email ON public.users USING btree (email) WHERE (email IS NOT NULL);


--
-- Name: uq_users_username; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uq_users_username ON public.users USING btree (username);


--
-- Name: academic_weeks academic_weeks_academic_year_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.academic_weeks
    ADD CONSTRAINT academic_weeks_academic_year_id_foreign FOREIGN KEY (academic_year_id) REFERENCES public.academic_years(id) ON DELETE CASCADE;


--
-- Name: academic_weeks academic_weeks_school_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.academic_weeks
    ADD CONSTRAINT academic_weeks_school_id_foreign FOREIGN KEY (school_id) REFERENCES public.schools(id) ON DELETE CASCADE;


--
-- Name: academic_weeks academic_weeks_term_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.academic_weeks
    ADD CONSTRAINT academic_weeks_term_id_foreign FOREIGN KEY (term_id) REFERENCES public.terms(id) ON DELETE CASCADE;


--
-- Name: book_categories book_categories_school_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.book_categories
    ADD CONSTRAINT book_categories_school_id_fkey FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: book_copies book_copies_book_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.book_copies
    ADD CONSTRAINT book_copies_book_id_fkey FOREIGN KEY (book_id) REFERENCES public.books(id);


--
-- Name: book_issues book_issues_copy_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.book_issues
    ADD CONSTRAINT book_issues_copy_id_fkey FOREIGN KEY (copy_id) REFERENCES public.book_copies(id);


--
-- Name: book_issues book_issues_issued_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.book_issues
    ADD CONSTRAINT book_issues_issued_by_fkey FOREIGN KEY (issued_by) REFERENCES public.users(id);


--
-- Name: book_issues book_issues_learner_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.book_issues
    ADD CONSTRAINT book_issues_learner_id_fkey FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: book_issues book_issues_school_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.book_issues
    ADD CONSTRAINT book_issues_school_id_fkey FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: book_issues book_issues_teacher_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.book_issues
    ADD CONSTRAINT book_issues_teacher_id_fkey FOREIGN KEY (teacher_id) REFERENCES public.teachers(id);


--
-- Name: book_returns book_returns_issue_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.book_returns
    ADD CONSTRAINT book_returns_issue_id_fkey FOREIGN KEY (issue_id) REFERENCES public.book_issues(id);


--
-- Name: book_returns book_returns_received_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.book_returns
    ADD CONSTRAINT book_returns_received_by_fkey FOREIGN KEY (received_by) REFERENCES public.users(id);


--
-- Name: books books_category_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.books
    ADD CONSTRAINT books_category_id_fkey FOREIGN KEY (category_id) REFERENCES public.book_categories(id);


--
-- Name: books books_school_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.books
    ADD CONSTRAINT books_school_id_fkey FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: attendance_alerts fk_aa_attendance; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_alerts
    ADD CONSTRAINT fk_aa_attendance FOREIGN KEY (attendance_id) REFERENCES public.learner_attendance(id);


--
-- Name: attendance_alerts fk_aa_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_alerts
    ADD CONSTRAINT fk_aa_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: attendance_alerts fk_aa_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_alerts
    ADD CONSTRAINT fk_aa_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: api_access_logs fk_aal_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_access_logs
    ADD CONSTRAINT fk_aal_user FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: academic_years fk_academic_year_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.academic_years
    ADD CONSTRAINT fk_academic_year_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: ai_insights fk_ai_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_insights
    ADD CONSTRAINT fk_ai_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: ai_conversations fk_aic_assistant; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_conversations
    ADD CONSTRAINT fk_aic_assistant FOREIGN KEY (assistant_id) REFERENCES public.ai_assistants(id);


--
-- Name: ai_conversations fk_aic_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_conversations
    ADD CONSTRAINT fk_aic_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: ai_conversations fk_aic_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_conversations
    ADD CONSTRAINT fk_aic_user FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: ai_generated_content fk_aigc_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_generated_content
    ADD CONSTRAINT fk_aigc_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: ai_prompts fk_aip_conversation; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_prompts
    ADD CONSTRAINT fk_aip_conversation FOREIGN KEY (conversation_id) REFERENCES public.ai_conversations(id) ON DELETE CASCADE;


--
-- Name: approvals fk_approval_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.approvals
    ADD CONSTRAINT fk_approval_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: approvals fk_approval_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.approvals
    ADD CONSTRAINT fk_approval_user FOREIGN KEY (approver_id) REFERENCES public.users(id);


--
-- Name: assessment_registrations fk_ar_created_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.assessment_registrations
    ADD CONSTRAINT fk_ar_created_by FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: assessment_registrations fk_ar_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.assessment_registrations
    ADD CONSTRAINT fk_ar_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: assessment_registrations fk_ar_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.assessment_registrations
    ADD CONSTRAINT fk_ar_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: assessment_types fk_at_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.assessment_types
    ADD CONSTRAINT fk_at_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: api_tokens fk_at_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_tokens
    ADD CONSTRAINT fk_at_user FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: attendance_sessions fk_att_session_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attendance_sessions
    ADD CONSTRAINT fk_att_session_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: audit_logs fk_audit_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT fk_audit_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: audit_logs fk_audit_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: broadcasts fk_b_channel; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcasts
    ADD CONSTRAINT fk_b_channel FOREIGN KEY (channel_id) REFERENCES public.communication_channels(id);


--
-- Name: broadcasts fk_b_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcasts
    ADD CONSTRAINT fk_b_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: broadcasts fk_b_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcasts
    ADD CONSTRAINT fk_b_user FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: bed_allocations fk_ba_bed; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bed_allocations
    ADD CONSTRAINT fk_ba_bed FOREIGN KEY (bed_id) REFERENCES public.hostel_beds(id);


--
-- Name: bed_allocations fk_ba_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bed_allocations
    ADD CONSTRAINT fk_ba_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: bed_allocations fk_ba_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bed_allocations
    ADD CONSTRAINT fk_ba_user FOREIGN KEY (allocated_by) REFERENCES public.users(id);


--
-- Name: brand_domains fk_bd_brand; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brand_domains
    ADD CONSTRAINT fk_bd_brand FOREIGN KEY (brand_id) REFERENCES public.white_label_brands(id);


--
-- Name: backup_files fk_bf_job; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backup_files
    ADD CONSTRAINT fk_bf_job FOREIGN KEY (backup_job_id) REFERENCES public.backup_jobs(id) ON DELETE CASCADE;


--
-- Name: backup_jobs fk_bj_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backup_jobs
    ADD CONSTRAINT fk_bj_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: brand_packages fk_bp_brand; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brand_packages
    ADD CONSTRAINT fk_bp_brand FOREIGN KEY (brand_id) REFERENCES public.white_label_brands(id);


--
-- Name: backup_policies fk_bp_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.backup_policies
    ADD CONSTRAINT fk_bp_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: brand_schools fk_bs_brand; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brand_schools
    ADD CONSTRAINT fk_bs_brand FOREIGN KEY (brand_id) REFERENCES public.white_label_brands(id);


--
-- Name: brand_schools fk_bs_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brand_schools
    ADD CONSTRAINT fk_bs_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: comment_bank fk_cb_grading; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comment_bank
    ADD CONSTRAINT fk_cb_grading FOREIGN KEY (grading_system_id) REFERENCES public.grading_systems(id);


--
-- Name: comment_bank fk_cb_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comment_bank
    ADD CONSTRAINT fk_cb_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: communication_logs fk_cl_channel; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.communication_logs
    ADD CONSTRAINT fk_cl_channel FOREIGN KEY (channel_id) REFERENCES public.communication_channels(id);


--
-- Name: communication_logs fk_cl_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.communication_logs
    ADD CONSTRAINT fk_cl_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: communication_logs fk_cl_sender; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.communication_logs
    ADD CONSTRAINT fk_cl_sender FOREIGN KEY (sent_by) REFERENCES public.users(id);


--
-- Name: communication_templates fk_ct_channel; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.communication_templates
    ADD CONSTRAINT fk_ct_channel FOREIGN KEY (channel_id) REFERENCES public.communication_channels(id);


--
-- Name: class_teachers fk_ct_grade; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.class_teachers
    ADD CONSTRAINT fk_ct_grade FOREIGN KEY (grade_id) REFERENCES public.grades(id);


--
-- Name: class_teachers fk_ct_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.class_teachers
    ADD CONSTRAINT fk_ct_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: communication_templates fk_ct_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.communication_templates
    ADD CONSTRAINT fk_ct_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: class_teachers fk_ct_stream; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.class_teachers
    ADD CONSTRAINT fk_ct_stream FOREIGN KEY (stream_id) REFERENCES public.streams(id);


--
-- Name: class_teachers fk_ct_teacher; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.class_teachers
    ADD CONSTRAINT fk_ct_teacher FOREIGN KEY (teacher_id) REFERENCES public.teachers(id);


--
-- Name: class_teachers fk_ct_term; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.class_teachers
    ADD CONSTRAINT fk_ct_term FOREIGN KEY (term_id) REFERENCES public.terms(id);


--
-- Name: class_teachers fk_ct_year; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.class_teachers
    ADD CONSTRAINT fk_ct_year FOREIGN KEY (academic_year_id) REFERENCES public.academic_years(id);


--
-- Name: discipline_actions fk_da_case; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.discipline_actions
    ADD CONSTRAINT fk_da_case FOREIGN KEY (case_id) REFERENCES public.discipline_cases(id) ON DELETE CASCADE;


--
-- Name: discipline_actions fk_da_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.discipline_actions
    ADD CONSTRAINT fk_da_user FOREIGN KEY (assigned_by) REFERENCES public.users(id);


--
-- Name: discipline_categories fk_dc_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.discipline_categories
    ADD CONSTRAINT fk_dc_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: discipline_cases fk_dcase_category; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.discipline_cases
    ADD CONSTRAINT fk_dcase_category FOREIGN KEY (category_id) REFERENCES public.discipline_categories(id);


--
-- Name: discipline_cases fk_dcase_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.discipline_cases
    ADD CONSTRAINT fk_dcase_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: discipline_cases fk_dcase_reported_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.discipline_cases
    ADD CONSTRAINT fk_dcase_reported_by FOREIGN KEY (reported_by) REFERENCES public.users(id);


--
-- Name: discipline_cases fk_dcase_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.discipline_cases
    ADD CONSTRAINT fk_dcase_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: documents fk_doc_category; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT fk_doc_category FOREIGN KEY (category_id) REFERENCES public.document_categories(id);


--
-- Name: documents fk_doc_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT fk_doc_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: dashboard_preferences fk_dp_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_preferences
    ADD CONSTRAINT fk_dp_user FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: dashboard_preferences fk_dp_widget; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_preferences
    ADD CONSTRAINT fk_dp_widget FOREIGN KEY (widget_id) REFERENCES public.dashboard_widgets(id);


--
-- Name: dashboard_widgets fk_dw_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dashboard_widgets
    ADD CONSTRAINT fk_dw_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: exam_learning_areas fk_ela_exam; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_learning_areas
    ADD CONSTRAINT fk_ela_exam FOREIGN KEY (exam_id) REFERENCES public.exams(id);


--
-- Name: exam_learning_areas fk_ela_learning_area; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_learning_areas
    ADD CONSTRAINT fk_ela_learning_area FOREIGN KEY (learning_area_id) REFERENCES public.learning_areas(id);


--
-- Name: exam_papers fk_ep_learning_area; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_papers
    ADD CONSTRAINT fk_ep_learning_area FOREIGN KEY (exam_learning_area_id) REFERENCES public.exam_learning_areas(id);


--
-- Name: exam_results fk_er_exam; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_results
    ADD CONSTRAINT fk_er_exam FOREIGN KEY (exam_id) REFERENCES public.exams(id);


--
-- Name: exam_results fk_er_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_results
    ADD CONSTRAINT fk_er_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: exam_results fk_er_learning_area; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_results
    ADD CONSTRAINT fk_er_learning_area FOREIGN KEY (learning_area_id) REFERENCES public.learning_areas(id);


--
-- Name: exam_results fk_er_paper; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_results
    ADD CONSTRAINT fk_er_paper FOREIGN KEY (paper_id) REFERENCES public.exam_papers(id);


--
-- Name: exams fk_exam_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exams
    ADD CONSTRAINT fk_exam_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: exams fk_exam_term; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exams
    ADD CONSTRAINT fk_exam_term FOREIGN KEY (term_id) REFERENCES public.terms(id);


--
-- Name: exams fk_exam_type; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exams
    ADD CONSTRAINT fk_exam_type FOREIGN KEY (assessment_type_id) REFERENCES public.assessment_types(id);


--
-- Name: exams fk_exam_year; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exams
    ADD CONSTRAINT fk_exam_year FOREIGN KEY (academic_year_id) REFERENCES public.academic_years(id);


--
-- Name: finance_adjustments fk_fa_approved_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.finance_adjustments
    ADD CONSTRAINT fk_fa_approved_by FOREIGN KEY (approved_by) REFERENCES public.users(id);


--
-- Name: finance_adjustments fk_fa_created_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.finance_adjustments
    ADD CONSTRAINT fk_fa_created_by FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: finance_adjustments fk_fa_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.finance_adjustments
    ADD CONSTRAINT fk_fa_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: finance_adjustments fk_fa_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.finance_adjustments
    ADD CONSTRAINT fk_fa_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: finance_audit_logs fk_fal_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.finance_audit_logs
    ADD CONSTRAINT fk_fal_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: finance_audit_logs fk_fal_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.finance_audit_logs
    ADD CONSTRAINT fk_fal_user FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: fee_categories fk_fc_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_categories
    ADD CONSTRAINT fk_fc_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: fee_discounts fk_fd_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_discounts
    ADD CONSTRAINT fk_fd_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: fee_invoice_items fk_fii_category; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_invoice_items
    ADD CONSTRAINT fk_fii_category FOREIGN KEY (fee_category_id) REFERENCES public.fee_categories(id);


--
-- Name: fee_invoice_items fk_fii_invoice; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_invoice_items
    ADD CONSTRAINT fk_fii_invoice FOREIGN KEY (invoice_id) REFERENCES public.fee_invoices(id) ON DELETE CASCADE;


--
-- Name: fee_refunds fk_fr_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_refunds
    ADD CONSTRAINT fk_fr_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: fee_refunds fk_fr_payment; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_refunds
    ADD CONSTRAINT fk_fr_payment FOREIGN KEY (payment_id) REFERENCES public.payments(id);


--
-- Name: fee_refunds fk_fr_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_refunds
    ADD CONSTRAINT fk_fr_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: fee_refunds fk_fr_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_refunds
    ADD CONSTRAINT fk_fr_user FOREIGN KEY (approved_by) REFERENCES public.users(id);


--
-- Name: finance_settings fk_fs_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.finance_settings
    ADD CONSTRAINT fk_fs_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: fee_structures fk_fst_category; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_structures
    ADD CONSTRAINT fk_fst_category FOREIGN KEY (fee_category_id) REFERENCES public.fee_categories(id);


--
-- Name: fee_structures fk_fst_grade; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_structures
    ADD CONSTRAINT fk_fst_grade FOREIGN KEY (grade_id) REFERENCES public.grades(id);


--
-- Name: fee_structures fk_fst_plan; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_structures
    ADD CONSTRAINT fk_fst_plan FOREIGN KEY (payment_plan_id) REFERENCES public.payment_plans(id);


--
-- Name: fee_structures fk_fst_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_structures
    ADD CONSTRAINT fk_fst_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: fee_structures fk_fst_term; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_structures
    ADD CONSTRAINT fk_fst_term FOREIGN KEY (term_id) REFERENCES public.terms(id);


--
-- Name: fee_structures fk_fst_year; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fee_structures
    ADD CONSTRAINT fk_fst_year FOREIGN KEY (academic_year_id) REFERENCES public.academic_years(id);


--
-- Name: group_announcements fk_ga_group; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.group_announcements
    ADD CONSTRAINT fk_ga_group FOREIGN KEY (group_id) REFERENCES public.school_groups(id);


--
-- Name: guidance_counselling_records fk_gcr_counsellor; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guidance_counselling_records
    ADD CONSTRAINT fk_gcr_counsellor FOREIGN KEY (counsellor_id) REFERENCES public.users(id);


--
-- Name: guidance_counselling_records fk_gcr_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guidance_counselling_records
    ADD CONSTRAINT fk_gcr_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: guidance_counselling_records fk_gcr_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guidance_counselling_records
    ADD CONSTRAINT fk_gcr_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: grades fk_grade_level; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grades
    ADD CONSTRAINT fk_grade_level FOREIGN KEY (education_level_id) REFERENCES public.education_levels(id);


--
-- Name: grades fk_grade_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grades
    ADD CONSTRAINT fk_grade_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: grading_systems fk_gs_level; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grading_systems
    ADD CONSTRAINT fk_gs_level FOREIGN KEY (education_level_id) REFERENCES public.education_levels(id);


--
-- Name: grading_systems fk_gs_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grading_systems
    ADD CONSTRAINT fk_gs_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: grading_scales fk_gscale_system; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grading_scales
    ADD CONSTRAINT fk_gscale_system FOREIGN KEY (grading_system_id) REFERENCES public.grading_systems(id);


--
-- Name: group_targets fk_gt_announcement; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.group_targets
    ADD CONSTRAINT fk_gt_announcement FOREIGN KEY (announcement_id) REFERENCES public.group_announcements(id) ON DELETE CASCADE;


--
-- Name: group_targets fk_gt_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.group_targets
    ADD CONSTRAINT fk_gt_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: group_users fk_gu_group; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.group_users
    ADD CONSTRAINT fk_gu_group FOREIGN KEY (group_id) REFERENCES public.school_groups(id);


--
-- Name: group_users fk_gu_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.group_users
    ADD CONSTRAINT fk_gu_user FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: hostel_attendance fk_ha_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hostel_attendance
    ADD CONSTRAINT fk_ha_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: hostel_attendance fk_ha_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hostel_attendance
    ADD CONSTRAINT fk_ha_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: hostel_attendance fk_ha_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hostel_attendance
    ADD CONSTRAINT fk_ha_user FOREIGN KEY (recorded_by) REFERENCES public.users(id);


--
-- Name: hostel_beds fk_hb_room; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hostel_beds
    ADD CONSTRAINT fk_hb_room FOREIGN KEY (room_id) REFERENCES public.hostel_rooms(id) ON DELETE CASCADE;


--
-- Name: hostel_incidents fk_hi_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hostel_incidents
    ADD CONSTRAINT fk_hi_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: hostel_incidents fk_hi_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hostel_incidents
    ADD CONSTRAINT fk_hi_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: hostel_incidents fk_hi_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hostel_incidents
    ADD CONSTRAINT fk_hi_user FOREIGN KEY (reported_by) REFERENCES public.users(id);


--
-- Name: hod_assignments fk_hod_learning_area; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hod_assignments
    ADD CONSTRAINT fk_hod_learning_area FOREIGN KEY (learning_area_id) REFERENCES public.learning_areas(id);


--
-- Name: hod_assignments fk_hod_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hod_assignments
    ADD CONSTRAINT fk_hod_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: hod_assignments fk_hod_teacher; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hod_assignments
    ADD CONSTRAINT fk_hod_teacher FOREIGN KEY (teacher_id) REFERENCES public.teachers(id);


--
-- Name: hod_assignments fk_hod_year; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hod_assignments
    ADD CONSTRAINT fk_hod_year FOREIGN KEY (academic_year_id) REFERENCES public.academic_years(id);


--
-- Name: hostels fk_hostel_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hostels
    ADD CONSTRAINT fk_hostel_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: hostel_rooms fk_hr_hostel; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hostel_rooms
    ADD CONSTRAINT fk_hr_hostel FOREIGN KEY (hostel_id) REFERENCES public.hostels(id) ON DELETE CASCADE;


--
-- Name: hostel_staff_assignments fk_hsa_hostel; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hostel_staff_assignments
    ADD CONSTRAINT fk_hsa_hostel FOREIGN KEY (hostel_id) REFERENCES public.hostels(id);


--
-- Name: hostel_staff_assignments fk_hsa_teacher; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hostel_staff_assignments
    ADD CONSTRAINT fk_hsa_teacher FOREIGN KEY (teacher_id) REFERENCES public.teachers(id);


--
-- Name: exam_invigilators fk_inv_exam; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_invigilators
    ADD CONSTRAINT fk_inv_exam FOREIGN KEY (exam_id) REFERENCES public.exams(id);


--
-- Name: exam_invigilators fk_inv_teacher; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_invigilators
    ADD CONSTRAINT fk_inv_teacher FOREIGN KEY (teacher_id) REFERENCES public.teachers(id);


--
-- Name: learner_attendance fk_la_grade; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_attendance
    ADD CONSTRAINT fk_la_grade FOREIGN KEY (grade_id) REFERENCES public.grades(id);


--
-- Name: learner_attendance fk_la_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_attendance
    ADD CONSTRAINT fk_la_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: learner_attendance fk_la_marked_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_attendance
    ADD CONSTRAINT fk_la_marked_by FOREIGN KEY (marked_by) REFERENCES public.users(id);


--
-- Name: learner_attendance fk_la_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_attendance
    ADD CONSTRAINT fk_la_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: learner_attendance fk_la_session; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_attendance
    ADD CONSTRAINT fk_la_session FOREIGN KEY (attendance_session_id) REFERENCES public.attendance_sessions(id);


--
-- Name: learner_attendance fk_la_status; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_attendance
    ADD CONSTRAINT fk_la_status FOREIGN KEY (attendance_status_id) REFERENCES public.attendance_statuses(id);


--
-- Name: learner_attendance fk_la_stream; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_attendance
    ADD CONSTRAINT fk_la_stream FOREIGN KEY (stream_id) REFERENCES public.streams(id);


--
-- Name: learning_area_analysis fk_laa_exam; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_area_analysis
    ADD CONSTRAINT fk_laa_exam FOREIGN KEY (exam_id) REFERENCES public.exams(id);


--
-- Name: learning_area_allocations fk_laa_grade; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_area_allocations
    ADD CONSTRAINT fk_laa_grade FOREIGN KEY (grade_id) REFERENCES public.grades(id);


--
-- Name: learning_area_allocations fk_laa_learning_area; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_area_allocations
    ADD CONSTRAINT fk_laa_learning_area FOREIGN KEY (learning_area_id) REFERENCES public.learning_areas(id);


--
-- Name: learning_area_analysis fk_laa_learning_area; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_area_analysis
    ADD CONSTRAINT fk_laa_learning_area FOREIGN KEY (learning_area_id) REFERENCES public.learning_areas(id);


--
-- Name: learning_area_allocations fk_laa_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_area_allocations
    ADD CONSTRAINT fk_laa_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: learning_area_analysis fk_laa_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_area_analysis
    ADD CONSTRAINT fk_laa_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: learning_area_constraints fk_lac_grade; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_area_constraints
    ADD CONSTRAINT fk_lac_grade FOREIGN KEY (grade_id) REFERENCES public.grades(id);


--
-- Name: learning_area_constraints fk_lac_learning_area; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_area_constraints
    ADD CONSTRAINT fk_lac_learning_area FOREIGN KEY (learning_area_id) REFERENCES public.learning_areas(id);


--
-- Name: learning_area_constraints fk_lac_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_area_constraints
    ADD CONSTRAINT fk_lac_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: learning_area_constraints fk_lac_stream; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_area_constraints
    ADD CONSTRAINT fk_lac_stream FOREIGN KEY (stream_id) REFERENCES public.streams(id);


--
-- Name: learning_area_relationships fk_lar_grade; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_area_relationships
    ADD CONSTRAINT fk_lar_grade FOREIGN KEY (grade_id) REFERENCES public.grades(id);


--
-- Name: learning_area_relationships fk_lar_la1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_area_relationships
    ADD CONSTRAINT fk_lar_la1 FOREIGN KEY (learning_area_1_id) REFERENCES public.learning_areas(id);


--
-- Name: learning_area_relationships fk_lar_la2; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_area_relationships
    ADD CONSTRAINT fk_lar_la2 FOREIGN KEY (learning_area_2_id) REFERENCES public.learning_areas(id);


--
-- Name: learning_area_relationships fk_lar_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learning_area_relationships
    ADD CONSTRAINT fk_lar_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: learner_documents fk_ld_created_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_documents
    ADD CONSTRAINT fk_ld_created_by FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: learner_discounts fk_ld_discount; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_discounts
    ADD CONSTRAINT fk_ld_discount FOREIGN KEY (discount_id) REFERENCES public.fee_discounts(id);


--
-- Name: learner_discounts fk_ld_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_discounts
    ADD CONSTRAINT fk_ld_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: learner_documents fk_ld_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_documents
    ADD CONSTRAINT fk_ld_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: learner_discounts fk_ld_term; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_discounts
    ADD CONSTRAINT fk_ld_term FOREIGN KEY (term_id) REFERENCES public.terms(id);


--
-- Name: learner_documents fk_ld_updated_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_documents
    ADD CONSTRAINT fk_ld_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: learner_discounts fk_ld_year; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_discounts
    ADD CONSTRAINT fk_ld_year FOREIGN KEY (academic_year_id) REFERENCES public.academic_years(id);


--
-- Name: learners fk_learner_grade; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learners
    ADD CONSTRAINT fk_learner_grade FOREIGN KEY (grade_id) REFERENCES public.grades(id);


--
-- Name: learners fk_learner_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learners
    ADD CONSTRAINT fk_learner_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: learners fk_learner_stream; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learners
    ADD CONSTRAINT fk_learner_stream FOREIGN KEY (stream_id) REFERENCES public.streams(id);


--
-- Name: learners fk_learners_deleted_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learners
    ADD CONSTRAINT fk_learners_deleted_by FOREIGN KEY (deleted_by) REFERENCES public.users(id);


--
-- Name: learner_fee_accounts fk_lfa_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_fee_accounts
    ADD CONSTRAINT fk_lfa_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: learner_fee_accounts fk_lfa_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_fee_accounts
    ADD CONSTRAINT fk_lfa_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: learner_fee_ledger fk_lfl_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_fee_ledger
    ADD CONSTRAINT fk_lfl_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: learner_fee_ledger fk_lfl_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_fee_ledger
    ADD CONSTRAINT fk_lfl_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: learner_fee_ledger fk_lfl_term; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_fee_ledger
    ADD CONSTRAINT fk_lfl_term FOREIGN KEY (term_id) REFERENCES public.terms(id);


--
-- Name: learner_fee_ledger fk_lfl_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_fee_ledger
    ADD CONSTRAINT fk_lfl_user FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: learner_fee_ledger fk_lfl_year; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_fee_ledger
    ADD CONSTRAINT fk_lfl_year FOREIGN KEY (academic_year_id) REFERENCES public.academic_years(id);


--
-- Name: level_learning_areas fk_lla_learning_area; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.level_learning_areas
    ADD CONSTRAINT fk_lla_learning_area FOREIGN KEY (learning_area_id) REFERENCES public.learning_areas(id);


--
-- Name: level_learning_areas fk_lla_level; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.level_learning_areas
    ADD CONSTRAINT fk_lla_level FOREIGN KEY (level_id) REFERENCES public.education_levels(id);


--
-- Name: learner_medical_profiles fk_lmp_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_medical_profiles
    ADD CONSTRAINT fk_lmp_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: lesson_notes fk_ln_lesson_plan; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lesson_notes
    ADD CONSTRAINT fk_ln_lesson_plan FOREIGN KEY (lesson_plan_id) REFERENCES public.lesson_plans(id);


--
-- Name: lesson_notes fk_ln_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lesson_notes
    ADD CONSTRAINT fk_ln_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: lesson_notes fk_ln_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lesson_notes
    ADD CONSTRAINT fk_ln_user FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: lesson_plans fk_lp_assignment; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lesson_plans
    ADD CONSTRAINT fk_lp_assignment FOREIGN KEY (teacher_assignment_id) REFERENCES public.teacher_assignments(id);


--
-- Name: lesson_plans fk_lp_scheme_lesson; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lesson_plans
    ADD CONSTRAINT fk_lp_scheme_lesson FOREIGN KEY (scheme_lesson_id) REFERENCES public.scheme_lessons(id);


--
-- Name: lesson_plans fk_lp_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lesson_plans
    ADD CONSTRAINT fk_lp_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: lesson_plans fk_lp_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lesson_plans
    ADD CONSTRAINT fk_lp_user FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: learner_transport_assignments fk_lta_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_transport_assignments
    ADD CONSTRAINT fk_lta_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: learner_transport_assignments fk_lta_route; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_transport_assignments
    ADD CONSTRAINT fk_lta_route FOREIGN KEY (route_id) REFERENCES public.transport_routes(id);


--
-- Name: learner_transport_assignments fk_lta_stop; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_transport_assignments
    ADD CONSTRAINT fk_lta_stop FOREIGN KEY (stop_id) REFERENCES public.route_stops(id);


--
-- Name: medication_administration fk_ma_medication; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medication_administration
    ADD CONSTRAINT fk_ma_medication FOREIGN KEY (medication_id) REFERENCES public.medications(id);


--
-- Name: medication_administration fk_ma_visit; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medication_administration
    ADD CONSTRAINT fk_ma_visit FOREIGN KEY (visit_id) REFERENCES public.sick_bay_visits(id);


--
-- Name: mpesa_callback_logs fk_mcl_transaction; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mpesa_callback_logs
    ADD CONSTRAINT fk_mcl_transaction FOREIGN KEY (transaction_id) REFERENCES public.mpesa_transactions(id);


--
-- Name: medications fk_med_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medications
    ADD CONSTRAINT fk_med_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: mark_entry_permissions fk_mep_exam; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mark_entry_permissions
    ADD CONSTRAINT fk_mep_exam FOREIGN KEY (exam_id) REFERENCES public.exams(id);


--
-- Name: module_installations fk_mi_module; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.module_installations
    ADD CONSTRAINT fk_mi_module FOREIGN KEY (module_id) REFERENCES public.marketplace_modules(id);


--
-- Name: module_installations fk_mi_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.module_installations
    ADD CONSTRAINT fk_mi_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: merit_lists fk_ml_exam; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.merit_lists
    ADD CONSTRAINT fk_ml_exam FOREIGN KEY (exam_id) REFERENCES public.exams(id);


--
-- Name: merit_lists fk_ml_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.merit_lists
    ADD CONSTRAINT fk_ml_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: merit_lists fk_ml_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.merit_lists
    ADD CONSTRAINT fk_ml_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: marketplace_modules fk_mm_category; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.marketplace_modules
    ADD CONSTRAINT fk_mm_category FOREIGN KEY (category_id) REFERENCES public.marketplace_categories(id);


--
-- Name: medical_referrals fk_mr_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medical_referrals
    ADD CONSTRAINT fk_mr_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: medical_referrals fk_mr_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medical_referrals
    ADD CONSTRAINT fk_mr_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: mpesa_transactions fk_mt_gateway; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mpesa_transactions
    ADD CONSTRAINT fk_mt_gateway FOREIGN KEY (gateway_id) REFERENCES public.school_payment_gateways(id);


--
-- Name: mpesa_transactions fk_mt_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mpesa_transactions
    ADD CONSTRAINT fk_mt_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: mpesa_transactions fk_mt_payment; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mpesa_transactions
    ADD CONSTRAINT fk_mt_payment FOREIGN KEY (payment_id) REFERENCES public.payments(id);


--
-- Name: mpesa_transactions fk_mt_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mpesa_transactions
    ADD CONSTRAINT fk_mt_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: module_versions fk_mv_module; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.module_versions
    ADD CONSTRAINT fk_mv_module FOREIGN KEY (module_id) REFERENCES public.marketplace_modules(id);


--
-- Name: notifications fk_notification_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT fk_notification_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: notifications fk_notification_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: notification_preferences fk_np_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_preferences
    ADD CONSTRAINT fk_np_school FOREIGN KEY (school_id) REFERENCES public.schools(id) ON DELETE CASCADE;


--
-- Name: online_applications fk_oa_grade; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.online_applications
    ADD CONSTRAINT fk_oa_grade FOREIGN KEY (grade_applied_for) REFERENCES public.grades(id);


--
-- Name: online_applications fk_oa_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.online_applications
    ADD CONSTRAINT fk_oa_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: payment_allocations fk_pa_invoice_item; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_allocations
    ADD CONSTRAINT fk_pa_invoice_item FOREIGN KEY (invoice_id) REFERENCES public.fee_invoice_items(id);


--
-- Name: payment_allocations fk_pa_payment; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_allocations
    ADD CONSTRAINT fk_pa_payment FOREIGN KEY (payment_id) REFERENCES public.payments(id) ON DELETE CASCADE;


--
-- Name: platform_audit_logs fk_pal_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.platform_audit_logs
    ADD CONSTRAINT fk_pal_user FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: parents fk_parent_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.parents
    ADD CONSTRAINT fk_parent_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: parents fk_parent_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.parents
    ADD CONSTRAINT fk_parent_user FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: parents fk_parents_deleted_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.parents
    ADD CONSTRAINT fk_parents_deleted_by FOREIGN KEY (deleted_by) REFERENCES public.parents(id);


--
-- Name: parent_learner_links fk_pl_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.parent_learner_links
    ADD CONSTRAINT fk_pl_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: parent_learner_links fk_pl_parent; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.parent_learner_links
    ADD CONSTRAINT fk_pl_parent FOREIGN KEY (parent_id) REFERENCES public.parents(id);


--
-- Name: parent_meetings fk_pm_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.parent_meetings
    ADD CONSTRAINT fk_pm_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: parent_meetings fk_pm_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.parent_meetings
    ADD CONSTRAINT fk_pm_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: payment_methods fk_pm_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_methods
    ADD CONSTRAINT fk_pm_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: parent_meetings fk_pm_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.parent_meetings
    ADD CONSTRAINT fk_pm_user FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: payment_plans fk_pp_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_plans
    ADD CONSTRAINT fk_pp_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: payment_plan_installments fk_ppi_plan; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_plan_installments
    ADD CONSTRAINT fk_ppi_plan FOREIGN KEY (payment_plan_id) REFERENCES public.payment_plans(id) ON DELETE CASCADE;


--
-- Name: pathway_recommendations fk_pr_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pathway_recommendations
    ADD CONSTRAINT fk_pr_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: pathway_recommendations fk_pr_year; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pathway_recommendations
    ADD CONSTRAINT fk_pr_year FOREIGN KEY (academic_year_id) REFERENCES public.academic_years(id);


--
-- Name: report_cards fk_rc_exam; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_cards
    ADD CONSTRAINT fk_rc_exam FOREIGN KEY (exam_id) REFERENCES public.exams(id);


--
-- Name: report_cards fk_rc_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_cards
    ADD CONSTRAINT fk_rc_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: room_constraints fk_rc_learning_area; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.room_constraints
    ADD CONSTRAINT fk_rc_learning_area FOREIGN KEY (learning_area_id) REFERENCES public.learning_areas(id);


--
-- Name: room_constraints fk_rc_room; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.room_constraints
    ADD CONSTRAINT fk_rc_room FOREIGN KEY (room_id) REFERENCES public.rooms(id);


--
-- Name: report_cards fk_rc_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_cards
    ADD CONSTRAINT fk_rc_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: room_constraints fk_rc_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.room_constraints
    ADD CONSTRAINT fk_rc_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: report_cards fk_rc_term; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_cards
    ADD CONSTRAINT fk_rc_term FOREIGN KEY (term_id) REFERENCES public.terms(id);


--
-- Name: report_cards fk_rc_year; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_cards
    ADD CONSTRAINT fk_rc_year FOREIGN KEY (academic_year_id) REFERENCES public.academic_years(id);


--
-- Name: report_card_learning_areas fk_rcla_learning_area; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_card_learning_areas
    ADD CONSTRAINT fk_rcla_learning_area FOREIGN KEY (learning_area_id) REFERENCES public.learning_areas(id);


--
-- Name: report_card_learning_areas fk_rcla_report; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_card_learning_areas
    ADD CONSTRAINT fk_rcla_report FOREIGN KEY (report_card_id) REFERENCES public.report_cards(id);


--
-- Name: restore_logs fk_rl_restore; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restore_logs
    ADD CONSTRAINT fk_rl_restore FOREIGN KEY (restore_request_id) REFERENCES public.restore_requests(id);


--
-- Name: rooms fk_room_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rooms
    ADD CONSTRAINT fk_room_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: rooms fk_rooms_room_type; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rooms
    ADD CONSTRAINT fk_rooms_room_type FOREIGN KEY (room_type_id) REFERENCES public.room_types(id);


--
-- Name: transport_routes fk_route_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transport_routes
    ADD CONSTRAINT fk_route_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: records_of_work fk_row_lesson_plan; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.records_of_work
    ADD CONSTRAINT fk_row_lesson_plan FOREIGN KEY (lesson_plan_id) REFERENCES public.lesson_plans(id);


--
-- Name: records_of_work fk_row_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.records_of_work
    ADD CONSTRAINT fk_row_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: records_of_work fk_row_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.records_of_work
    ADD CONSTRAINT fk_row_user FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: role_permissions fk_rp_permission; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES public.permissions(id);


--
-- Name: role_permissions fk_rp_role; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES public.roles(id);


--
-- Name: restore_requests fk_rr_backup; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restore_requests
    ADD CONSTRAINT fk_rr_backup FOREIGN KEY (backup_file_id) REFERENCES public.backup_files(id);


--
-- Name: restore_requests fk_rr_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restore_requests
    ADD CONSTRAINT fk_rr_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: report_templates fk_rt_level; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_templates
    ADD CONSTRAINT fk_rt_level FOREIGN KEY (education_level_id) REFERENCES public.education_levels(id);


--
-- Name: report_templates fk_rt_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.report_templates
    ADD CONSTRAINT fk_rt_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: system_announcements fk_sa_created_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_announcements
    ADD CONSTRAINT fk_sa_created_by FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: super_admins fk_sa_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.super_admins
    ADD CONSTRAINT fk_sa_user FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: sick_bay_visits fk_sbv_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sick_bay_visits
    ADD CONSTRAINT fk_sbv_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: sick_bay_visits fk_sbv_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sick_bay_visits
    ADD CONSTRAINT fk_sbv_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: sick_bay_visits fk_sbv_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sick_bay_visits
    ADD CONSTRAINT fk_sbv_user FOREIGN KEY (attended_by) REFERENCES public.users(id);


--
-- Name: schemes_of_work fk_scheme_grade; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schemes_of_work
    ADD CONSTRAINT fk_scheme_grade FOREIGN KEY (grade_id) REFERENCES public.grades(id);


--
-- Name: schemes_of_work fk_scheme_learning_area; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schemes_of_work
    ADD CONSTRAINT fk_scheme_learning_area FOREIGN KEY (learning_area_id) REFERENCES public.learning_areas(id);


--
-- Name: schemes_of_work fk_scheme_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schemes_of_work
    ADD CONSTRAINT fk_scheme_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: schemes_of_work fk_scheme_term; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schemes_of_work
    ADD CONSTRAINT fk_scheme_term FOREIGN KEY (term_id) REFERENCES public.terms(id);


--
-- Name: schemes_of_work fk_scheme_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schemes_of_work
    ADD CONSTRAINT fk_scheme_user FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: schemes_of_work fk_scheme_year; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schemes_of_work
    ADD CONSTRAINT fk_scheme_year FOREIGN KEY (academic_year_id) REFERENCES public.academic_years(id);


--
-- Name: school_settings fk_school_settings_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_settings
    ADD CONSTRAINT fk_school_settings_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: school_subscriptions fk_school_subscriptions_activated_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_subscriptions
    ADD CONSTRAINT fk_school_subscriptions_activated_by FOREIGN KEY (activated_by) REFERENCES public.users(id);


--
-- Name: schools fk_schools_deleted_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schools
    ADD CONSTRAINT fk_schools_deleted_by FOREIGN KEY (deleted_by) REFERENCES public.users(id);


--
-- Name: sms_credit_purchases fk_scp_package; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sms_credit_purchases
    ADD CONSTRAINT fk_scp_package FOREIGN KEY (sms_package_id) REFERENCES public.sms_packages(id);


--
-- Name: sms_credit_purchases fk_scp_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sms_credit_purchases
    ADD CONSTRAINT fk_scp_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: sms_credit_purchases fk_scp_verified_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sms_credit_purchases
    ADD CONSTRAINT fk_scp_verified_by FOREIGN KEY (verified_by) REFERENCES public.users(id);


--
-- Name: staff_documents fk_sd_created_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staff_documents
    ADD CONSTRAINT fk_sd_created_by FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: staff_documents fk_sd_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staff_documents
    ADD CONSTRAINT fk_sd_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: staff_documents fk_sd_updated_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staff_documents
    ADD CONSTRAINT fk_sd_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: school_group_members fk_sgm_group; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_group_members
    ADD CONSTRAINT fk_sgm_group FOREIGN KEY (group_id) REFERENCES public.school_groups(id);


--
-- Name: school_group_members fk_sgm_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_group_members
    ADD CONSTRAINT fk_sgm_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: school_integrations fk_si_provider; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_integrations
    ADD CONSTRAINT fk_si_provider FOREIGN KEY (provider_id) REFERENCES public.integration_providers(id);


--
-- Name: school_integrations fk_si_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_integrations
    ADD CONSTRAINT fk_si_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: scheme_lessons fk_sl_week; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scheme_lessons
    ADD CONSTRAINT fk_sl_week FOREIGN KEY (week_id) REFERENCES public.scheme_weeks(id);


--
-- Name: school_learning_areas fk_sla_learning_area; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_learning_areas
    ADD CONSTRAINT fk_sla_learning_area FOREIGN KEY (learning_area_id) REFERENCES public.learning_areas(id);


--
-- Name: school_learning_areas fk_sla_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_learning_areas
    ADD CONSTRAINT fk_sla_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: school_module_subscriptions fk_sms_module; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_module_subscriptions
    ADD CONSTRAINT fk_sms_module FOREIGN KEY (module_id) REFERENCES public.marketplace_modules(id);


--
-- Name: school_module_subscriptions fk_sms_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_module_subscriptions
    ADD CONSTRAINT fk_sms_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: sms_wallets fk_sms_wallet_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sms_wallets
    ADD CONSTRAINT fk_sms_wallet_school FOREIGN KEY (school_id) REFERENCES public.schools(id) ON DELETE CASCADE;


--
-- Name: subscription_payments fk_sp_request; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_payments
    ADD CONSTRAINT fk_sp_request FOREIGN KEY (payment_request_id) REFERENCES public.subscription_payment_requests(id);


--
-- Name: school_payment_gateways fk_spg_created_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_payment_gateways
    ADD CONSTRAINT fk_spg_created_by FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: school_payment_gateways fk_spg_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_payment_gateways
    ADD CONSTRAINT fk_spg_school FOREIGN KEY (school_id) REFERENCES public.schools(id) ON DELETE CASCADE;


--
-- Name: school_payment_gateways fk_spg_updated_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_payment_gateways
    ADD CONSTRAINT fk_spg_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: subscription_payment_requests fk_spr_package; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_payment_requests
    ADD CONSTRAINT fk_spr_package FOREIGN KEY (package_id) REFERENCES public.subscription_packages(id);


--
-- Name: subscription_payment_requests fk_spr_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_payment_requests
    ADD CONSTRAINT fk_spr_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: saved_reports fk_sr_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.saved_reports
    ADD CONSTRAINT fk_sr_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: school_subscriptions fk_ss_package; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_subscriptions
    ADD CONSTRAINT fk_ss_package FOREIGN KEY (package_id) REFERENCES public.subscription_packages(id);


--
-- Name: school_subscriptions fk_ss_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_subscriptions
    ADD CONSTRAINT fk_ss_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: system_settings fk_ss_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_settings
    ADD CONSTRAINT fk_ss_user FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: school_status_logs fk_ssl_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_status_logs
    ADD CONSTRAINT fk_ssl_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: school_status_logs fk_ssl_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_status_logs
    ADD CONSTRAINT fk_ssl_user FOREIGN KEY (changed_by) REFERENCES public.users(id);


--
-- Name: sms_transactions fk_st_log; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sms_transactions
    ADD CONSTRAINT fk_st_log FOREIGN KEY (communication_log_id) REFERENCES public.communication_logs(id);


--
-- Name: sms_transactions fk_st_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sms_transactions
    ADD CONSTRAINT fk_st_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: support_tickets fk_st_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT fk_st_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: support_ticket_comments fk_stc_ticket; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.support_ticket_comments
    ADD CONSTRAINT fk_stc_ticket FOREIGN KEY (ticket_id) REFERENCES public.support_tickets(id) ON DELETE CASCADE;


--
-- Name: route_stops fk_stop_route; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.route_stops
    ADD CONSTRAINT fk_stop_route FOREIGN KEY (route_id) REFERENCES public.transport_routes(id) ON DELETE CASCADE;


--
-- Name: streams fk_stream_grade; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.streams
    ADD CONSTRAINT fk_stream_grade FOREIGN KEY (grade_id) REFERENCES public.grades(id);


--
-- Name: streams fk_stream_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.streams
    ADD CONSTRAINT fk_stream_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: subscription_upgrade_requests fk_sur_current_package; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_upgrade_requests
    ADD CONSTRAINT fk_sur_current_package FOREIGN KEY (current_package_id) REFERENCES public.subscription_packages(id);


--
-- Name: subscription_upgrade_requests fk_sur_requested_package; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_upgrade_requests
    ADD CONSTRAINT fk_sur_requested_package FOREIGN KEY (requested_package_id) REFERENCES public.subscription_packages(id);


--
-- Name: subscription_upgrade_requests fk_sur_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_upgrade_requests
    ADD CONSTRAINT fk_sur_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: scheme_weeks fk_sw_scheme; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scheme_weeks
    ADD CONSTRAINT fk_sw_scheme FOREIGN KEY (scheme_id) REFERENCES public.schemes_of_work(id);


--
-- Name: teacher_assignments fk_ta_grade; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teacher_assignments
    ADD CONSTRAINT fk_ta_grade FOREIGN KEY (grade_id) REFERENCES public.grades(id);


--
-- Name: transport_attendance fk_ta_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transport_attendance
    ADD CONSTRAINT fk_ta_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: teacher_assignments fk_ta_learning_area; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teacher_assignments
    ADD CONSTRAINT fk_ta_learning_area FOREIGN KEY (learning_area_id) REFERENCES public.learning_areas(id);


--
-- Name: teacher_availability fk_ta_period; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teacher_availability
    ADD CONSTRAINT fk_ta_period FOREIGN KEY (period_id) REFERENCES public.timetable_periods(id);


--
-- Name: teacher_assignments fk_ta_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teacher_assignments
    ADD CONSTRAINT fk_ta_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: teacher_availability fk_ta_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teacher_availability
    ADD CONSTRAINT fk_ta_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: transport_attendance fk_ta_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transport_attendance
    ADD CONSTRAINT fk_ta_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: teacher_assignments fk_ta_stream; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teacher_assignments
    ADD CONSTRAINT fk_ta_stream FOREIGN KEY (stream_id) REFERENCES public.streams(id);


--
-- Name: teacher_assignments fk_ta_teacher; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teacher_assignments
    ADD CONSTRAINT fk_ta_teacher FOREIGN KEY (teacher_id) REFERENCES public.teachers(id);


--
-- Name: teacher_availability fk_ta_teacher; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teacher_availability
    ADD CONSTRAINT fk_ta_teacher FOREIGN KEY (teacher_id) REFERENCES public.teachers(id);


--
-- Name: teacher_assignments fk_ta_term; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teacher_assignments
    ADD CONSTRAINT fk_ta_term FOREIGN KEY (term_id) REFERENCES public.terms(id);


--
-- Name: transport_attendance fk_ta_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transport_attendance
    ADD CONSTRAINT fk_ta_user FOREIGN KEY (recorded_by) REFERENCES public.users(id);


--
-- Name: teacher_assignments fk_ta_year; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teacher_assignments
    ADD CONSTRAINT fk_ta_year FOREIGN KEY (academic_year_id) REFERENCES public.academic_years(id);


--
-- Name: teacher_constraints fk_tc_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teacher_constraints
    ADD CONSTRAINT fk_tc_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: timetable_conflicts fk_tc_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_conflicts
    ADD CONSTRAINT fk_tc_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: timetable_constraints fk_tc_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_constraints
    ADD CONSTRAINT fk_tc_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: teacher_constraints fk_tc_teacher; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teacher_constraints
    ADD CONSTRAINT fk_tc_teacher FOREIGN KEY (teacher_id) REFERENCES public.teachers(id);


--
-- Name: timetable_conflicts fk_tc_timetable; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_conflicts
    ADD CONSTRAINT fk_tc_timetable FOREIGN KEY (timetable_id) REFERENCES public.timetables(id);


--
-- Name: timetable_entries fk_te_grade; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_entries
    ADD CONSTRAINT fk_te_grade FOREIGN KEY (grade_id) REFERENCES public.grades(id);


--
-- Name: timetable_entries fk_te_learning_area; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_entries
    ADD CONSTRAINT fk_te_learning_area FOREIGN KEY (learning_area_id) REFERENCES public.learning_areas(id);


--
-- Name: timetable_entries fk_te_period; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_entries
    ADD CONSTRAINT fk_te_period FOREIGN KEY (period_id) REFERENCES public.timetable_periods(id);


--
-- Name: timetable_entries fk_te_room; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_entries
    ADD CONSTRAINT fk_te_room FOREIGN KEY (room_id) REFERENCES public.rooms(id);


--
-- Name: timetable_entries fk_te_stream; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_entries
    ADD CONSTRAINT fk_te_stream FOREIGN KEY (stream_id) REFERENCES public.streams(id);


--
-- Name: timetable_entries fk_te_teacher; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_entries
    ADD CONSTRAINT fk_te_teacher FOREIGN KEY (teacher_id) REFERENCES public.teachers(id);


--
-- Name: timetable_entries fk_te_timetable; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_entries
    ADD CONSTRAINT fk_te_timetable FOREIGN KEY (timetable_id) REFERENCES public.timetables(id) ON DELETE CASCADE;


--
-- Name: teachers fk_teacher_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teachers
    ADD CONSTRAINT fk_teacher_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: teachers fk_teacher_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teachers
    ADD CONSTRAINT fk_teacher_user FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: teachers fk_teachers_deleted_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teachers
    ADD CONSTRAINT fk_teachers_deleted_by FOREIGN KEY (deleted_by) REFERENCES public.teachers(id);


--
-- Name: terms fk_term_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.terms
    ADD CONSTRAINT fk_term_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: terms fk_term_year; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.terms
    ADD CONSTRAINT fk_term_year FOREIGN KEY (academic_year_id) REFERENCES public.academic_years(id);


--
-- Name: timetable_generation_runs fk_tgr_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_generation_runs
    ADD CONSTRAINT fk_tgr_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: timetable_generation_runs fk_tgr_timetable; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_generation_runs
    ADD CONSTRAINT fk_tgr_timetable FOREIGN KEY (timetable_id) REFERENCES public.timetables(id);


--
-- Name: timetable_generation_runs fk_tgr_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_generation_runs
    ADD CONSTRAINT fk_tgr_user FOREIGN KEY (generated_by) REFERENCES public.users(id);


--
-- Name: transport_incidents fk_ti_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transport_incidents
    ADD CONSTRAINT fk_ti_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: transport_incidents fk_ti_vehicle; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transport_incidents
    ADD CONSTRAINT fk_ti_vehicle FOREIGN KEY (vehicle_id) REFERENCES public.vehicles(id);


--
-- Name: timetable_profiles fk_tp_level; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_profiles
    ADD CONSTRAINT fk_tp_level FOREIGN KEY (education_level_id) REFERENCES public.education_levels(id);


--
-- Name: timetable_profiles fk_tp_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_profiles
    ADD CONSTRAINT fk_tp_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: timetable_publications fk_tp_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_publications
    ADD CONSTRAINT fk_tp_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: timetable_publications fk_tp_timetable; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_publications
    ADD CONSTRAINT fk_tp_timetable FOREIGN KEY (timetable_id) REFERENCES public.timetables(id);


--
-- Name: timetable_publications fk_tp_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_publications
    ADD CONSTRAINT fk_tp_user FOREIGN KEY (published_by) REFERENCES public.users(id);


--
-- Name: timetable_periods fk_tperiod_profile; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_periods
    ADD CONSTRAINT fk_tperiod_profile FOREIGN KEY (timetable_profile_id) REFERENCES public.timetable_profiles(id) ON DELETE CASCADE;


--
-- Name: transaction_reconciliations fk_tr_invoice; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaction_reconciliations
    ADD CONSTRAINT fk_tr_invoice FOREIGN KEY (invoice_id) REFERENCES public.fee_invoices(id);


--
-- Name: transaction_reconciliations fk_tr_learner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaction_reconciliations
    ADD CONSTRAINT fk_tr_learner FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: transaction_reconciliations fk_tr_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaction_reconciliations
    ADD CONSTRAINT fk_tr_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: transaction_reconciliations fk_tr_transaction; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaction_reconciliations
    ADD CONSTRAINT fk_tr_transaction FOREIGN KEY (mpesa_transaction_id) REFERENCES public.mpesa_transactions(id);


--
-- Name: transaction_reconciliations fk_tr_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaction_reconciliations
    ADD CONSTRAINT fk_tr_user FOREIGN KEY (reconciled_by) REFERENCES public.users(id);


--
-- Name: timetable_substitutions fk_ts_absent_teacher; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_substitutions
    ADD CONSTRAINT fk_ts_absent_teacher FOREIGN KEY (absent_teacher_id) REFERENCES public.teachers(id);


--
-- Name: timetable_substitutions fk_ts_approved_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_substitutions
    ADD CONSTRAINT fk_ts_approved_by FOREIGN KEY (approved_by) REFERENCES public.users(id);


--
-- Name: timetable_substitutions fk_ts_entry; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_substitutions
    ADD CONSTRAINT fk_ts_entry FOREIGN KEY (timetable_entry_id) REFERENCES public.timetable_entries(id);


--
-- Name: timetable_substitutions fk_ts_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_substitutions
    ADD CONSTRAINT fk_ts_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: timetable_substitutions fk_ts_sub_teacher; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetable_substitutions
    ADD CONSTRAINT fk_ts_sub_teacher FOREIGN KEY (substitute_teacher_id) REFERENCES public.teachers(id);


--
-- Name: timetables fk_tt_profile; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetables
    ADD CONSTRAINT fk_tt_profile FOREIGN KEY (timetable_profile_id) REFERENCES public.timetable_profiles(id);


--
-- Name: timetables fk_tt_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetables
    ADD CONSTRAINT fk_tt_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: timetables fk_tt_term; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetables
    ADD CONSTRAINT fk_tt_term FOREIGN KEY (term_id) REFERENCES public.terms(id);


--
-- Name: timetables fk_tt_year; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.timetables
    ADD CONSTRAINT fk_tt_year FOREIGN KEY (academic_year_id) REFERENCES public.academic_years(id);


--
-- Name: users fk_users_deleted_by; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT fk_users_deleted_by FOREIGN KEY (deleted_by) REFERENCES public.users(id);


--
-- Name: users fk_users_role; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES public.roles(id);


--
-- Name: users fk_users_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT fk_users_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: vehicle_assignments fk_va_route; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_assignments
    ADD CONSTRAINT fk_va_route FOREIGN KEY (route_id) REFERENCES public.transport_routes(id);


--
-- Name: vehicle_assignments fk_va_vehicle; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_assignments
    ADD CONSTRAINT fk_va_vehicle FOREIGN KEY (vehicle_id) REFERENCES public.vehicles(id);


--
-- Name: vehicles fk_vehicle_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicles
    ADD CONSTRAINT fk_vehicle_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: website_events fk_we_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.website_events
    ADD CONSTRAINT fk_we_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: website_galleries fk_wg_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.website_galleries
    ADD CONSTRAINT fk_wg_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: website_gallery_images fk_wgi_gallery; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.website_gallery_images
    ADD CONSTRAINT fk_wgi_gallery FOREIGN KEY (gallery_id) REFERENCES public.website_galleries(id) ON DELETE CASCADE;


--
-- Name: white_label_brands fk_wlb_partner; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.white_label_brands
    ADD CONSTRAINT fk_wlb_partner FOREIGN KEY (partner_id) REFERENCES public.license_partners(id);


--
-- Name: website_news fk_wn_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.website_news
    ADD CONSTRAINT fk_wn_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: website_pages fk_wp_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.website_pages
    ADD CONSTRAINT fk_wp_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: website_settings fk_ws_school; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.website_settings
    ADD CONSTRAINT fk_ws_school FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: inventory_categories inventory_categories_school_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_categories
    ADD CONSTRAINT inventory_categories_school_id_fkey FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: inventory_items inventory_items_category_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_items
    ADD CONSTRAINT inventory_items_category_id_fkey FOREIGN KEY (category_id) REFERENCES public.inventory_categories(id);


--
-- Name: inventory_items inventory_items_school_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_items
    ADD CONSTRAINT inventory_items_school_id_fkey FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: inventory_transactions inventory_transactions_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_transactions
    ADD CONSTRAINT inventory_transactions_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.inventory_items(id);


--
-- Name: inventory_transactions inventory_transactions_school_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_transactions
    ADD CONSTRAINT inventory_transactions_school_id_fkey FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: learner_documents learner_documents_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_documents
    ADD CONSTRAINT learner_documents_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.documents(id);


--
-- Name: learner_documents learner_documents_learner_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_documents
    ADD CONSTRAINT learner_documents_learner_id_fkey FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: learner_parents learner_parents_learner_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_parents
    ADD CONSTRAINT learner_parents_learner_id_foreign FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: learner_parents learner_parents_parent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.learner_parents
    ADD CONSTRAINT learner_parents_parent_id_foreign FOREIGN KEY (parent_id) REFERENCES public.parents(id);


--
-- Name: library_fines library_fines_issue_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.library_fines
    ADD CONSTRAINT library_fines_issue_id_fkey FOREIGN KEY (issue_id) REFERENCES public.book_issues(id);


--
-- Name: library_fines library_fines_learner_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.library_fines
    ADD CONSTRAINT library_fines_learner_id_fkey FOREIGN KEY (learner_id) REFERENCES public.learners(id);


--
-- Name: library_fines library_fines_school_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.library_fines
    ADD CONSTRAINT library_fines_school_id_fkey FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: package_features package_features_feature_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.package_features
    ADD CONSTRAINT package_features_feature_id_fkey FOREIGN KEY (feature_id) REFERENCES public.subscription_features(id) ON DELETE CASCADE;


--
-- Name: package_features package_features_package_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.package_features
    ADD CONSTRAINT package_features_package_id_fkey FOREIGN KEY (package_id) REFERENCES public.subscription_packages(id) ON DELETE CASCADE;


--
-- Name: scheme_lessons scheme_lessons_scheme_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scheme_lessons
    ADD CONSTRAINT scheme_lessons_scheme_id_foreign FOREIGN KEY (scheme_id) REFERENCES public.schemes_of_work(id) ON DELETE CASCADE;


--
-- Name: school_benchmarks school_benchmarks_benchmark_group_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_benchmarks
    ADD CONSTRAINT school_benchmarks_benchmark_group_id_fkey FOREIGN KEY (benchmark_group_id) REFERENCES public.benchmark_groups(id);


--
-- Name: school_benchmarks school_benchmarks_metric_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_benchmarks
    ADD CONSTRAINT school_benchmarks_metric_id_fkey FOREIGN KEY (metric_id) REFERENCES public.benchmark_metrics(id);


--
-- Name: school_benchmarks school_benchmarks_school_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_benchmarks
    ADD CONSTRAINT school_benchmarks_school_id_fkey FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: staff_documents staff_documents_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staff_documents
    ADD CONSTRAINT staff_documents_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.documents(id);


--
-- Name: staff_documents staff_documents_teacher_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.staff_documents
    ADD CONSTRAINT staff_documents_teacher_id_fkey FOREIGN KEY (teacher_id) REFERENCES public.teachers(id);


--
-- Name: stock_adjustments stock_adjustments_adjusted_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_adjustments
    ADD CONSTRAINT stock_adjustments_adjusted_by_fkey FOREIGN KEY (adjusted_by) REFERENCES public.users(id);


--
-- Name: stock_adjustments stock_adjustments_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_adjustments
    ADD CONSTRAINT stock_adjustments_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.inventory_items(id);


--
-- Name: stock_adjustments stock_adjustments_school_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_adjustments
    ADD CONSTRAINT stock_adjustments_school_id_fkey FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: stock_issue_items stock_issue_items_issue_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_issue_items
    ADD CONSTRAINT stock_issue_items_issue_id_fkey FOREIGN KEY (issue_id) REFERENCES public.stock_issues(id) ON DELETE CASCADE;


--
-- Name: stock_issue_items stock_issue_items_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_issue_items
    ADD CONSTRAINT stock_issue_items_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.inventory_items(id);


--
-- Name: stock_issues stock_issues_issued_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_issues
    ADD CONSTRAINT stock_issues_issued_by_fkey FOREIGN KEY (issued_by) REFERENCES public.users(id);


--
-- Name: stock_issues stock_issues_school_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_issues
    ADD CONSTRAINT stock_issues_school_id_fkey FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: stock_receipt_items stock_receipt_items_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_receipt_items
    ADD CONSTRAINT stock_receipt_items_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.inventory_items(id);


--
-- Name: stock_receipt_items stock_receipt_items_receipt_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_receipt_items
    ADD CONSTRAINT stock_receipt_items_receipt_id_fkey FOREIGN KEY (receipt_id) REFERENCES public.stock_receipts(id) ON DELETE CASCADE;


--
-- Name: stock_receipts stock_receipts_received_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_receipts
    ADD CONSTRAINT stock_receipts_received_by_fkey FOREIGN KEY (received_by) REFERENCES public.users(id);


--
-- Name: stock_receipts stock_receipts_school_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_receipts
    ADD CONSTRAINT stock_receipts_school_id_fkey FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: stock_receipts stock_receipts_supplier_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stock_receipts
    ADD CONSTRAINT stock_receipts_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id);


--
-- Name: suppliers suppliers_school_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_school_id_fkey FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: user_roles user_roles_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(id);


--
-- Name: user_roles user_roles_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: workflow_actions workflow_actions_action_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_actions
    ADD CONSTRAINT workflow_actions_action_by_fkey FOREIGN KEY (action_by) REFERENCES public.users(id);


--
-- Name: workflow_actions workflow_actions_instance_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_actions
    ADD CONSTRAINT workflow_actions_instance_id_fkey FOREIGN KEY (instance_id) REFERENCES public.workflow_instances(id);


--
-- Name: workflow_definitions workflow_definitions_school_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_definitions
    ADD CONSTRAINT workflow_definitions_school_id_fkey FOREIGN KEY (school_id) REFERENCES public.schools(id);


--
-- Name: workflow_instances workflow_instances_workflow_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_instances
    ADD CONSTRAINT workflow_instances_workflow_id_fkey FOREIGN KEY (workflow_id) REFERENCES public.workflow_definitions(id);


--
-- Name: workflow_steps workflow_steps_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_steps
    ADD CONSTRAINT workflow_steps_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(id);


--
-- Name: workflow_steps workflow_steps_workflow_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_steps
    ADD CONSTRAINT workflow_steps_workflow_id_fkey FOREIGN KEY (workflow_id) REFERENCES public.workflow_definitions(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict gAa8IvfBKlnO8v9kxaH388nsb5lLOducN2wgTj5xlkYaNpAWO7ydyNPc7bjarzz

--
-- PostgreSQL database dump
--

\restrict 0iUe1XBAmcoyn6Ow7izG0LDT0DTe2BXokoMM1qACknzWMoC9fjXdt3wAPRteajd

-- Dumped from database version 18.4
-- Dumped by pg_dump version 18.4

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000001_create_cache_table	1
2	0001_01_01_000002_create_jobs_table	1
3	2026_06_16_170808_create_learner_parents_table	1
4	2026_06_20_075845_create_teacher_allocations_table	2
5	2026_06_20_093904_create_academic_weeks_table	3
6	2026_06_20_094059_add_scheme_id_to_scheme_lessons_table	3
7	2026_06_20_101107_drop_teacher_allocations_table	4
8	2026_06_20_101629_create_curriculum_coverage_table	5
9	2026_06_24_083736_add_grade_and_stream_to_fee_invoices_table	6
10	2026_06_24_083736_add_posted_by_to_payments_table	6
11	2026_06_24_083737_add_ip_and_user_agent_to_finance_audit_logs_table	6
12	2026_06_24_083737_add_status_to_finance_adjustments_table	6
13	2026_06_24_084928_add_currency_to_finance_settings_table	7
14	2026_06_24_084928_add_installments_to_payment_plans_table	7
15	2026_06_24_084928_add_is_online_to_payment_methods_table	7
16	2026_06_24_092237_add_stream_due_date_and_notes_to_fee_structures_table	8
17	2026_06_24_093817_add_credit_limit_and_last_payment_date_to_learner_fee_accounts_table	9
18	2026_06_24_095542_add_account_status_to_learner_fee_accounts_table	10
19	2026_06_24_100812_add_fee_structure_generated_by_and_notes_to_fee_invoices_table	11
20	2026_06_24_101710_add_posted_and_cancelled_dates_to_fee_invoices_table	12
21	2026_06_24_103115_add_allocated_amount_and_payment_channel_to_payments_table	13
22	2026_06_24_105735_repair_payment_allocations_table	14
23	2026_06_24_110228_add_created_by_to_payment_allocations_table	15
24	2026_06_24_185812_add_reconciliation_fields_to_mpesa_transactions_table	16
25	2026_06_25_072354_add_module_and_description_to_audit_logs_table	17
26	2026_06_28_172859_add_unique_scheme_lesson_number_to_scheme_lessons_table	18
27	2026_06_28_175806_add_unique_teacher_assignment_scheme_lesson_to_lesson_plans_table	19
28	2026_06_28_181601_add_unique_lesson_plan_to_records_of_work_table	19
29	2026_07_11_000001_harden_teacher_assignments_table	20
30	2026_07_11_000002_harden_schemes_of_work_table	21
31	2026_07_11_000003_harden_scheme_lessons_table	22
32	2026_07_11_000004_harden_lesson_plans_table	23
33	2026_07_11_000005_harden_lesson_notes_table	24
34	2026_07_11_000006_harden_records_of_work_table	25
35	2026_07_11_000007_harden_curriculum_coverage_table	26
36	2026_07_11_000008_harden_assessment_types_table	27
37	2026_07_11_000009_harden_assessment_registrations_table	28
38	2026_07_11_000010_harden_exams_table	29
39	2026_07_11_000011_harden_exam_learning_areas_table	30
40	2026_07_11_000012_harden_exam_papers_table	31
41	2026_07_11_000013_harden_mark_entry_permissions_table	32
42	2026_07_11_000014_harden_exam_results_table	33
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 42, true);


--
-- PostgreSQL database dump complete
--

\unrestrict 0iUe1XBAmcoyn6Ow7izG0LDT0DTe2BXokoMM1qACknzWMoC9fjXdt3wAPRteajd

