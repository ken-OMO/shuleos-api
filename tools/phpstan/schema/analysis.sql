-- Generated for static analysis only. Never execute against a database.
CREATE TABLE `academic_weeks` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `academic_year_id` CHAR(36) NOT NULL,
    `term_id` CHAR(36) NOT NULL,
    `week_number` INTEGER NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `active` BOOLEAN NOT NULL,
    `created_at` DATETIME NOT NULL
);

CREATE TABLE `academic_years` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `year_name` VARCHAR(20) NOT NULL,
    `start_date` DATE NULL,
    `end_date` DATE NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `ai_assistants` (
    `id` CHAR(36) NOT NULL,
    `assistant_code` VARCHAR(100) NOT NULL,
    `assistant_name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `ai_conversations` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `assistant_id` CHAR(36) NOT NULL,
    `title` VARCHAR(255) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `ai_generated_content` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `content_type` VARCHAR(100) NULL,
    `title` VARCHAR(255) NULL,
    `generated_content` TEXT NULL,
    `created_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `ai_insights` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `insight_type` VARCHAR(100) NULL,
    `insight_title` VARCHAR(255) NULL,
    `insight_description` TEXT NULL,
    `severity` VARCHAR(20) NULL,
    `generated_at` DATETIME NULL
);

CREATE TABLE `ai_prompts` (
    `id` CHAR(36) NOT NULL,
    `conversation_id` CHAR(36) NOT NULL,
    `prompt_text` TEXT NOT NULL,
    `response_text` TEXT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `analytics_dimensions` (
    `id` CHAR(36) NOT NULL,
    `dimension_type` VARCHAR(100) NULL,
    `dimension_name` VARCHAR(255) NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `api_access_logs` (
    `id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NULL,
    `endpoint` VARCHAR(255) NULL,
    `request_method` VARCHAR(10) NULL,
    `response_status` INTEGER NULL,
    `ip_address` VARCHAR(100) NULL,
    `request_time` DATETIME NULL
);

CREATE TABLE `api_clients` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NULL,
    `client_name` VARCHAR(255) NOT NULL,
    `client_type` VARCHAR(50) NULL,
    `api_key` VARCHAR(255) NOT NULL,
    `api_secret` VARCHAR(255) NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `api_tokens` (
    `id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `token` TEXT NOT NULL,
    `expires_at` DATETIME NULL,
    `revoked` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `approvals` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `entity_type` VARCHAR(100) NOT NULL,
    `entity_id` CHAR(36) NOT NULL,
    `approver_id` CHAR(36) NOT NULL,
    `approval_status` VARCHAR(50) NULL,
    `comments` TEXT NULL,
    `approved_at` DATETIME NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `assessment_registrations` (
    `id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `assessment_type` VARCHAR(50) NOT NULL,
    `assessment_year` INTEGER NOT NULL,
    `candidate_number` VARCHAR(100) NULL,
    `registration_number` VARCHAR(100) NULL,
    `status` VARCHAR(30) NULL,
    `created_at` DATETIME NULL,
    `school_id` CHAR(36) NOT NULL,
    `created_by` CHAR(36) NULL,
    `is_deleted` BOOLEAN NOT NULL,
    `deleted_at` DATETIME NULL,
    `deleted_by` CHAR(36) NULL
);

CREATE TABLE `assessment_types` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `assessment_type_name` VARCHAR(100) NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL,
    `is_deleted` BOOLEAN NOT NULL,
    `deleted_at` DATETIME NULL,
    `deleted_by` CHAR(36) NULL
);

CREATE TABLE `attendance_alerts` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `attendance_id` CHAR(36) NOT NULL,
    `parent_notified` BOOLEAN NULL,
    `notification_method` VARCHAR(50) NULL,
    `notified_at` DATETIME NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `attendance_sessions` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `session_name` VARCHAR(50) NOT NULL,
    `session_order` INTEGER NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `attendance_statuses` (
    `id` CHAR(36) NOT NULL,
    `status_name` VARCHAR(50) NOT NULL,
    `status_code` VARCHAR(10) NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `audit_logs` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NULL,
    `user_id` CHAR(36) NULL,
    `action` VARCHAR(255) NULL,
    `table_name` VARCHAR(255) NULL,
    `record_id` CHAR(36) NULL,
    `old_values` JSON NULL,
    `new_values` JSON NULL,
    `ip_address` VARCHAR(100) NULL,
    `user_agent` TEXT NULL,
    `created_at` DATETIME NULL,
    `module` VARCHAR(255) NOT NULL,
    `description` TEXT NULL
);

CREATE TABLE `backup_files` (
    `id` CHAR(36) NOT NULL,
    `backup_job_id` CHAR(36) NOT NULL,
    `file_name` VARCHAR(255) NULL,
    `storage_path` TEXT NULL,
    `backup_date` DATETIME NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `backup_jobs` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `started_at` DATETIME NULL,
    `completed_at` DATETIME NULL,
    `status` VARCHAR(30) NULL,
    `file_size_mb` DECIMAL(12,2) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `backup_policies` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `backup_frequency` VARCHAR(20) NOT NULL,
    `retention_days` INTEGER NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `bed_allocations` (
    `id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `bed_id` CHAR(36) NOT NULL,
    `allocation_date` DATE NOT NULL,
    `release_date` DATE NULL,
    `active` BOOLEAN NULL,
    `allocated_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `benchmark_groups` (
    `id` CHAR(36) NOT NULL,
    `group_name` VARCHAR(255) NULL,
    `description` TEXT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `benchmark_metrics` (
    `id` CHAR(36) NOT NULL,
    `metric_code` VARCHAR(100) NULL,
    `metric_name` VARCHAR(255) NULL,
    `description` TEXT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `book_categories` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `category_name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `book_copies` (
    `id` CHAR(36) NOT NULL,
    `book_id` CHAR(36) NOT NULL,
    `accession_number` VARCHAR(100) NOT NULL,
    `barcode` VARCHAR(100) NULL,
    `status` VARCHAR(30) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `book_issues` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `copy_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NULL,
    `teacher_id` CHAR(36) NULL,
    `issue_date` DATE NOT NULL,
    `due_date` DATE NOT NULL,
    `issued_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `book_returns` (
    `id` CHAR(36) NOT NULL,
    `issue_id` CHAR(36) NOT NULL,
    `return_date` DATE NOT NULL,
    `condition_on_return` VARCHAR(50) NULL,
    `remarks` TEXT NULL,
    `received_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `books` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `category_id` CHAR(36) NOT NULL,
    `isbn` VARCHAR(50) NULL,
    `title` VARCHAR(255) NOT NULL,
    `author` VARCHAR(255) NULL,
    `publisher` VARCHAR(255) NULL,
    `publication_year` INTEGER NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `brand_domains` (
    `id` CHAR(36) NOT NULL,
    `brand_id` CHAR(36) NOT NULL,
    `domain_name` VARCHAR(255) NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `brand_packages` (
    `id` CHAR(36) NOT NULL,
    `brand_id` CHAR(36) NOT NULL,
    `package_name` VARCHAR(255) NULL,
    `amount` DECIMAL(12,2) NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `brand_schools` (
    `id` CHAR(36) NOT NULL,
    `brand_id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `joined_at` DATETIME NULL
);

CREATE TABLE `broadcasts` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `message_body` TEXT NOT NULL,
    `channel_id` CHAR(36) NOT NULL,
    `target_group` VARCHAR(100) NULL,
    `total_recipients` INTEGER NULL,
    `total_sms_used` INTEGER NULL,
    `status` VARCHAR(30) NULL,
    `scheduled_at` DATETIME NULL,
    `sent_at` DATETIME NULL,
    `created_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `cache` (
    `key` VARCHAR(255) NOT NULL,
    `value` TEXT NOT NULL,
    `expiration` INTEGER NOT NULL
);

CREATE TABLE `cache_locks` (
    `key` VARCHAR(255) NOT NULL,
    `owner` VARCHAR(255) NOT NULL,
    `expiration` INTEGER NOT NULL
);

CREATE TABLE `class_teachers` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `teacher_id` CHAR(36) NOT NULL,
    `grade_id` CHAR(36) NOT NULL,
    `stream_id` CHAR(36) NOT NULL,
    `academic_year_id` CHAR(36) NOT NULL,
    `term_id` CHAR(36) NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `comment_bank` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `grading_system_id` CHAR(36) NOT NULL,
    `min_score` DECIMAL(5,2) NULL,
    `max_score` DECIMAL(5,2) NULL,
    `grade_code` VARCHAR(20) NULL,
    `comment_type` VARCHAR(50) NULL,
    `comment_text` TEXT NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `communication_channels` (
    `id` CHAR(36) NOT NULL,
    `channel_code` VARCHAR(50) NOT NULL,
    `channel_name` VARCHAR(100) NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `communication_logs` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NULL,
    `parent_id` CHAR(36) NULL,
    `channel_id` CHAR(36) NOT NULL,
    `recipient` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(255) NULL,
    `message_body` TEXT NOT NULL,
    `delivery_status` VARCHAR(30) NULL,
    `sent_by` CHAR(36) NULL,
    `sent_at` DATETIME NULL
);

CREATE TABLE `communication_templates` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `channel_id` CHAR(36) NOT NULL,
    `template_name` VARCHAR(255) NOT NULL,
    `template_code` VARCHAR(100) NULL,
    `message_body` TEXT NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `curriculum_coverage` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `teacher_assignment_id` CHAR(36) NOT NULL,
    `scheme_id` CHAR(36) NOT NULL,
    `scheme_lesson_id` CHAR(36) NOT NULL,
    `record_of_work_id` CHAR(36) NOT NULL,
    `date_completed` DATE NOT NULL,
    `strand` VARCHAR(255) NOT NULL,
    `sub_strand` VARCHAR(255) NOT NULL,
    `week_number` INTEGER NOT NULL,
    `completed` BOOLEAN NOT NULL,
    `created_at` DATETIME NOT NULL,
    `is_deleted` BOOLEAN NOT NULL,
    `deleted_at` DATETIME NULL,
    `deleted_by` CHAR(36) NULL
);

CREATE TABLE `dashboard_preferences` (
    `id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `widget_id` CHAR(36) NOT NULL,
    `visible` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `dashboard_widgets` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `widget_code` VARCHAR(100) NOT NULL,
    `widget_name` VARCHAR(255) NOT NULL,
    `widget_type` VARCHAR(50) NULL,
    `active` BOOLEAN NULL,
    `display_order` INTEGER NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `discipline_actions` (
    `id` CHAR(36) NOT NULL,
    `case_id` CHAR(36) NOT NULL,
    `action_type` VARCHAR(100) NOT NULL,
    `action_date` DATE NOT NULL,
    `remarks` TEXT NULL,
    `assigned_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `discipline_cases` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `category_id` CHAR(36) NOT NULL,
    `reported_by` CHAR(36) NULL,
    `incident_date` DATE NOT NULL,
    `incident_time` TIME NULL,
    `location` VARCHAR(255) NULL,
    `description` TEXT NOT NULL,
    `status` VARCHAR(50) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `discipline_categories` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `category_name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `severity_level` VARCHAR(20) NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `document_categories` (
    `id` CHAR(36) NOT NULL,
    `category_name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `documents` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `category_id` CHAR(36) NOT NULL,
    `document_name` VARCHAR(255) NOT NULL,
    `file_path` TEXT NULL,
    `file_type` VARCHAR(50) NULL,
    `file_size` BIGINT NULL,
    `uploaded_by` CHAR(36) NULL,
    `uploaded_at` DATETIME NULL
);

CREATE TABLE `education_levels` (
    `id` CHAR(36) NOT NULL,
    `level_name` VARCHAR(100) NOT NULL,
    `level_order` INTEGER NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `exam_invigilators` (
    `id` CHAR(36) NOT NULL,
    `exam_id` CHAR(36) NOT NULL,
    `teacher_id` CHAR(36) NOT NULL,
    `grade_id` CHAR(36) NULL,
    `stream_id` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `exam_learning_areas` (
    `id` CHAR(36) NOT NULL,
    `exam_id` CHAR(36) NOT NULL,
    `learning_area_id` CHAR(36) NOT NULL,
    `number_of_papers` INTEGER NULL,
    `total_marks` INTEGER NULL,
    `created_at` DATETIME NULL,
    `is_deleted` BOOLEAN NOT NULL,
    `deleted_at` DATETIME NULL,
    `deleted_by` CHAR(36) NULL
);

CREATE TABLE `exam_papers` (
    `id` CHAR(36) NOT NULL,
    `exam_learning_area_id` CHAR(36) NOT NULL,
    `paper_name` VARCHAR(100) NULL,
    `paper_number` INTEGER NULL,
    `max_marks` INTEGER NULL,
    `created_at` DATETIME NULL,
    `is_deleted` BOOLEAN NOT NULL,
    `deleted_at` DATETIME NULL,
    `deleted_by` CHAR(36) NULL
);

CREATE TABLE `exam_results` (
    `id` CHAR(36) NOT NULL,
    `exam_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `learning_area_id` CHAR(36) NOT NULL,
    `paper_id` CHAR(36) NULL,
    `marks` DECIMAL(6,2) NULL,
    `entered_by` CHAR(36) NULL,
    `created_at` DATETIME NULL,
    `is_deleted` BOOLEAN NOT NULL,
    `deleted_at` DATETIME NULL,
    `deleted_by` CHAR(36) NULL
);

CREATE TABLE `exams` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `exam_name` VARCHAR(255) NOT NULL,
    `assessment_type_id` CHAR(36) NOT NULL,
    `academic_year_id` CHAR(36) NOT NULL,
    `term_id` CHAR(36) NOT NULL,
    `start_date` DATE NULL,
    `end_date` DATE NULL,
    `active` BOOLEAN NULL,
    `created_by` CHAR(36) NULL,
    `created_at` DATETIME NULL,
    `status` VARCHAR(20) NOT NULL,
    `is_deleted` BOOLEAN NOT NULL,
    `deleted_at` DATETIME NULL,
    `deleted_by` CHAR(36) NULL
);

CREATE TABLE `failed_jobs` (
    `id` BIGINT NOT NULL,
    `uuid` VARCHAR(255) NOT NULL,
    `connection` TEXT NOT NULL,
    `queue` TEXT NOT NULL,
    `payload` TEXT NOT NULL,
    `exception` TEXT NOT NULL,
    `failed_at` DATETIME NOT NULL
);

CREATE TABLE `fee_arrears` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `academic_year_id` CHAR(36) NOT NULL,
    `term_id` CHAR(36) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `carried_forward_to_term_id` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `fee_categories` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `category_name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `is_system` BOOLEAN NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `fee_discounts` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `discount_name` VARCHAR(255) NOT NULL,
    `discount_type` VARCHAR(20) NOT NULL,
    `discount_value` DECIMAL(12,2) NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `fee_invoice_items` (
    `id` CHAR(36) NOT NULL,
    `invoice_id` CHAR(36) NOT NULL,
    `fee_category_id` CHAR(36) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `fee_invoices` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `academic_year_id` CHAR(36) NOT NULL,
    `term_id` CHAR(36) NOT NULL,
    `invoice_number` VARCHAR(100) NOT NULL,
    `total_amount` DECIMAL(12,2) NOT NULL,
    `amount_paid` DECIMAL(12,2) NULL,
    `balance` DECIMAL(12,2) NOT NULL,
    `status` VARCHAR(50) NULL,
    `invoice_date` DATE NOT NULL,
    `due_date` DATE NULL,
    `created_at` DATETIME NULL,
    `grade_id` CHAR(36) NULL,
    `stream_id` CHAR(36) NULL,
    `fee_structure_id` CHAR(36) NULL,
    `generated_by` CHAR(36) NULL,
    `notes` TEXT NULL,
    `posted_at` DATETIME NULL,
    `cancelled_at` DATETIME NULL
);

CREATE TABLE `fee_refunds` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `payment_id` CHAR(36) NULL,
    `refund_amount` DECIMAL(12,2) NOT NULL,
    `reason` TEXT NULL,
    `approved_by` CHAR(36) NULL,
    `refund_date` DATE NOT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `fee_structures` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `academic_year_id` CHAR(36) NOT NULL,
    `term_id` CHAR(36) NOT NULL,
    `grade_id` CHAR(36) NOT NULL,
    `fee_category_id` CHAR(36) NOT NULL,
    `payment_plan_id` CHAR(36) NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL,
    `stream_id` CHAR(36) NULL,
    `due_date` DATE NULL,
    `notes` TEXT NULL
);

CREATE TABLE `fee_transaction_types` (
    `id` CHAR(36) NOT NULL,
    `transaction_type` VARCHAR(50) NULL
);

CREATE TABLE `finance_adjustments` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `adjustment_type` VARCHAR(50) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `reason` TEXT NOT NULL,
    `approved_by` CHAR(36) NULL,
    `created_by` CHAR(36) NULL,
    `created_at` DATETIME NULL,
    `status` VARCHAR(255) NOT NULL
);

CREATE TABLE `finance_audit_logs` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `table_name` VARCHAR(100) NULL,
    `record_id` CHAR(36) NULL,
    `old_values` JSON NULL,
    `new_values` JSON NULL,
    `created_at` DATETIME NULL,
    `ip_address` VARCHAR(255) NULL,
    `user_agent` TEXT NULL
);

CREATE TABLE `finance_settings` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `allow_partial_payments` BOOLEAN NULL,
    `allow_overpayments` BOOLEAN NULL,
    `auto_generate_invoices` BOOLEAN NULL,
    `require_fee_clearance_for_results` BOOLEAN NULL,
    `require_fee_clearance_for_report_cards` BOOLEAN NULL,
    `require_fee_clearance_for_exams` BOOLEAN NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL,
    `currency` VARCHAR(255) NOT NULL
);

CREATE TABLE `grades` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `grade_name` VARCHAR(50) NOT NULL,
    `grade_order` INTEGER NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL,
    `education_level_id` CHAR(36) NULL
);

CREATE TABLE `grading_scales` (
    `id` CHAR(36) NOT NULL,
    `grading_system_id` CHAR(36) NOT NULL,
    `grade_code` VARCHAR(20) NOT NULL,
    `grade_description` VARCHAR(255) NULL,
    `min_score` DECIMAL(5,2) NULL,
    `max_score` DECIMAL(5,2) NULL,
    `points` INTEGER NULL,
    `sort_order` INTEGER NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `grading_systems` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `grading_name` VARCHAR(255) NOT NULL,
    `education_level_id` CHAR(36) NOT NULL,
    `uses_points` BOOLEAN NULL,
    `uses_marks` BOOLEAN NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `group_announcements` (
    `id` CHAR(36) NOT NULL,
    `group_id` CHAR(36) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `message_body` TEXT NOT NULL,
    `created_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `group_targets` (
    `id` CHAR(36) NOT NULL,
    `announcement_id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `group_users` (
    `id` CHAR(36) NOT NULL,
    `group_id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `role_name` VARCHAR(100) NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `guidance_counselling_records` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `session_date` DATE NOT NULL,
    `session_type` VARCHAR(100) NULL,
    `notes` TEXT NULL,
    `counsellor_id` CHAR(36) NULL,
    `follow_up_date` DATE NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `hod_assignments` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `teacher_id` CHAR(36) NOT NULL,
    `learning_area_id` CHAR(36) NOT NULL,
    `academic_year_id` CHAR(36) NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `hostel_attendance` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `attendance_date` DATE NOT NULL,
    `attendance_session` VARCHAR(20) NOT NULL,
    `status` VARCHAR(20) NOT NULL,
    `recorded_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `hostel_beds` (
    `id` CHAR(36) NOT NULL,
    `room_id` CHAR(36) NOT NULL,
    `bed_number` VARCHAR(50) NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `hostel_incidents` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `incident_date` DATE NOT NULL,
    `incident_type` VARCHAR(100) NULL,
    `description` TEXT NOT NULL,
    `action_taken` TEXT NULL,
    `reported_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `hostel_rooms` (
    `id` CHAR(36) NOT NULL,
    `hostel_id` CHAR(36) NOT NULL,
    `room_name` VARCHAR(100) NOT NULL,
    `floor_number` INTEGER NULL,
    `capacity` INTEGER NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `hostels` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `hostel_name` VARCHAR(255) NOT NULL,
    `hostel_type` VARCHAR(20) NOT NULL,
    `capacity` INTEGER NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `integration_providers` (
    `id` CHAR(36) NOT NULL,
    `provider_name` VARCHAR(255) NOT NULL,
    `provider_type` VARCHAR(50) NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `inventory_categories` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `category_name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `inventory_items` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `category_id` CHAR(36) NOT NULL,
    `item_code` VARCHAR(100) NULL,
    `item_name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `unit_of_measure` VARCHAR(50) NULL,
    `minimum_stock` INTEGER NULL,
    `current_stock` INTEGER NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `inventory_transactions` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `item_id` CHAR(36) NOT NULL,
    `transaction_type` VARCHAR(30) NULL,
    `quantity` INTEGER NOT NULL,
    `balance_after` INTEGER NULL,
    `reference_type` VARCHAR(50) NULL,
    `reference_id` CHAR(36) NULL,
    `transaction_date` DATETIME NULL
);

CREATE TABLE `job_batches` (
    `id` VARCHAR(255) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `total_jobs` INTEGER NOT NULL,
    `pending_jobs` INTEGER NOT NULL,
    `failed_jobs` INTEGER NOT NULL,
    `failed_job_ids` TEXT NOT NULL,
    `options` TEXT NULL,
    `cancelled_at` INTEGER NULL,
    `created_at` INTEGER NOT NULL,
    `finished_at` INTEGER NULL
);

CREATE TABLE `jobs` (
    `id` BIGINT NOT NULL,
    `queue` VARCHAR(255) NOT NULL,
    `payload` TEXT NOT NULL,
    `attempts` SMALLINT NOT NULL,
    `reserved_at` INTEGER NULL,
    `available_at` INTEGER NOT NULL,
    `created_at` INTEGER NOT NULL
);

CREATE TABLE `learner_attendance` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `grade_id` CHAR(36) NOT NULL,
    `stream_id` CHAR(36) NOT NULL,
    `attendance_session_id` CHAR(36) NOT NULL,
    `attendance_status_id` CHAR(36) NOT NULL,
    `attendance_date` DATE NOT NULL,
    `remarks` TEXT NULL,
    `marked_by` CHAR(36) NULL,
    `created_at` DATETIME NULL,
    `updated_at` DATETIME NULL
);

CREATE TABLE `learner_discounts` (
    `id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `discount_id` CHAR(36) NOT NULL,
    `academic_year_id` CHAR(36) NOT NULL,
    `term_id` CHAR(36) NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `learner_documents` (
    `id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `document_id` CHAR(36) NOT NULL,
    `document_number` VARCHAR(100) NULL,
    `issue_date` DATE NULL,
    `expiry_date` DATE NULL,
    `remarks` TEXT NULL,
    `created_at` DATETIME NULL,
    `school_id` CHAR(36) NOT NULL,
    `created_by` CHAR(36) NULL,
    `updated_at` DATETIME NULL,
    `updated_by` CHAR(36) NULL
);

CREATE TABLE `learner_fee_accounts` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `account_number` VARCHAR(50) NULL,
    `current_balance` DECIMAL(12,2) NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL,
    `credit_limit` DECIMAL(12,2) NOT NULL,
    `last_payment_date` DATE NULL,
    `account_status` VARCHAR(255) NOT NULL
);

CREATE TABLE `learner_fee_ledger` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `academic_year_id` CHAR(36) NULL,
    `term_id` CHAR(36) NULL,
    `transaction_date` DATETIME NOT NULL,
    `transaction_type` VARCHAR(50) NOT NULL,
    `reference_type` VARCHAR(50) NULL,
    `reference_id` CHAR(36) NULL,
    `debit_amount` DECIMAL(12,2) NULL,
    `credit_amount` DECIMAL(12,2) NULL,
    `running_balance` DECIMAL(12,2) NULL,
    `description` TEXT NULL,
    `created_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `learner_medical_profiles` (
    `id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `blood_group` VARCHAR(10) NULL,
    `allergies` TEXT NULL,
    `chronic_conditions` TEXT NULL,
    `emergency_contact` VARCHAR(50) NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `learner_parents` (
    `id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `parent_id` CHAR(36) NOT NULL,
    `is_primary_contact` BOOLEAN NOT NULL,
    `active` BOOLEAN NOT NULL,
    `created_at` DATETIME NOT NULL
);

CREATE TABLE `learner_transport_assignments` (
    `id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `route_id` CHAR(36) NOT NULL,
    `stop_id` CHAR(36) NOT NULL,
    `start_date` DATE NULL,
    `end_date` DATE NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `learners` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `admission_no` VARCHAR(50) NOT NULL,
    `upi` VARCHAR(50) NULL,
    `first_name` VARCHAR(100) NOT NULL,
    `middle_name` VARCHAR(100) NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `gender` VARCHAR(20) NULL,
    `date_of_birth` DATE NULL,
    `grade_id` CHAR(36) NOT NULL,
    `stream_id` CHAR(36) NOT NULL,
    `admission_date` DATE NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL,
    `updated_at` DATETIME NULL,
    `assessment_no` VARCHAR(50) NULL,
    `is_deleted` BOOLEAN NULL,
    `deleted_at` DATETIME NULL,
    `deleted_by` CHAR(36) NULL
);

CREATE TABLE `learning_area_allocations` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `grade_id` CHAR(36) NOT NULL,
    `learning_area_id` CHAR(36) NOT NULL,
    `lessons_per_week` INTEGER NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `learning_area_analysis` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `exam_id` CHAR(36) NOT NULL,
    `learning_area_id` CHAR(36) NOT NULL,
    `grade_id` CHAR(36) NULL,
    `stream_id` CHAR(36) NULL,
    `learners_assessed` INTEGER NULL,
    `highest_score` DECIMAL(6,2) NULL,
    `lowest_score` DECIMAL(6,2) NULL,
    `mean_score` DECIMAL(6,2) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `learning_area_constraints` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learning_area_id` CHAR(36) NOT NULL,
    `grade_id` CHAR(36) NULL,
    `stream_id` CHAR(36) NULL,
    `constraint_type` VARCHAR(100) NOT NULL,
    `constraint_value` VARCHAR(255) NULL,
    `priority` VARCHAR(20) NULL,
    `active` BOOLEAN NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `learning_area_relationships` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learning_area_1_id` CHAR(36) NOT NULL,
    `learning_area_2_id` CHAR(36) NOT NULL,
    `grade_id` CHAR(36) NULL,
    `relationship_type` VARCHAR(100) NOT NULL,
    `priority` INTEGER NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `learning_areas` (
    `id` CHAR(36) NOT NULL,
    `learning_area_name` VARCHAR(255) NOT NULL,
    `short_name` VARCHAR(50) NULL,
    `category` VARCHAR(100) NULL,
    `is_core` BOOLEAN NULL,
    `is_examined` BOOLEAN NULL,
    `is_custom` BOOLEAN NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `lesson_notes` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `lesson_plan_id` CHAR(36) NOT NULL,
    `note_content` TEXT NOT NULL,
    `created_by` CHAR(36) NOT NULL,
    `created_at` DATETIME NULL,
    `is_deleted` BOOLEAN NOT NULL,
    `deleted_at` DATETIME NULL,
    `deleted_by` CHAR(36) NULL
);

CREATE TABLE `lesson_plans` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `teacher_assignment_id` CHAR(36) NOT NULL,
    `scheme_lesson_id` CHAR(36) NOT NULL,
    `lesson_date` DATE NOT NULL,
    `introduction` TEXT NULL,
    `lesson_development` TEXT NULL,
    `conclusion` TEXT NULL,
    `reflection` TEXT NULL,
    `status` VARCHAR(50) NULL,
    `created_by` CHAR(36) NOT NULL,
    `created_at` DATETIME NULL,
    `is_deleted` BOOLEAN NOT NULL,
    `deleted_at` DATETIME NULL,
    `deleted_by` CHAR(36) NULL
);

CREATE TABLE `level_learning_areas` (
    `id` CHAR(36) NOT NULL,
    `level_id` CHAR(36) NOT NULL,
    `learning_area_id` CHAR(36) NOT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `library_fines` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NULL,
    `issue_id` CHAR(36) NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `reason` TEXT NULL,
    `paid` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `license_partners` (
    `id` CHAR(36) NOT NULL,
    `partner_name` VARCHAR(255) NOT NULL,
    `contact_person` VARCHAR(255) NULL,
    `email` VARCHAR(255) NULL,
    `phone` VARCHAR(50) NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `mark_entry_permissions` (
    `id` CHAR(36) NOT NULL,
    `exam_id` CHAR(36) NOT NULL,
    `role_name` VARCHAR(100) NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL,
    `opens_at` DATETIME NULL,
    `closes_at` DATETIME NULL,
    `is_deleted` BOOLEAN NOT NULL,
    `deleted_at` DATETIME NULL,
    `deleted_by` CHAR(36) NULL
);

CREATE TABLE `marketplace_categories` (
    `id` CHAR(36) NOT NULL,
    `category_name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `marketplace_modules` (
    `id` CHAR(36) NOT NULL,
    `category_id` CHAR(36) NOT NULL,
    `module_code` VARCHAR(100) NOT NULL,
    `module_name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `module_type` VARCHAR(30) NULL,
    `monthly_price` DECIMAL(12,2) NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `medical_conditions` (
    `id` CHAR(36) NOT NULL,
    `condition_name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `medical_referrals` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `referral_date` DATE NOT NULL,
    `referred_to` VARCHAR(255) NULL,
    `reason` TEXT NULL,
    `follow_up_notes` TEXT NULL,
    `created_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `medication_administration` (
    `id` CHAR(36) NOT NULL,
    `visit_id` CHAR(36) NOT NULL,
    `medication_id` CHAR(36) NOT NULL,
    `dosage` VARCHAR(100) NULL,
    `administered_by` CHAR(36) NULL,
    `administered_at` DATETIME NULL,
    `notes` TEXT NULL
);

CREATE TABLE `medications` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `medication_name` VARCHAR(255) NOT NULL,
    `quantity_available` INTEGER NULL,
    `expiry_date` DATE NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `merit_lists` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `exam_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `grade_id` CHAR(36) NOT NULL,
    `stream_id` CHAR(36) NULL,
    `total_score` DECIMAL(6,2) NULL,
    `total_points` DECIMAL(6,2) NULL,
    `stream_position` INTEGER NULL,
    `grade_position` INTEGER NULL,
    `school_position` INTEGER NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `migrations` (
    `id` INTEGER NOT NULL,
    `migration` VARCHAR(255) NOT NULL,
    `batch` INTEGER NOT NULL
);

CREATE TABLE `module_installations` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `module_id` CHAR(36) NOT NULL,
    `installed_by` CHAR(36) NULL,
    `installation_date` DATETIME NULL,
    `status` VARCHAR(30) NULL
);

CREATE TABLE `module_versions` (
    `id` CHAR(36) NOT NULL,
    `module_id` CHAR(36) NOT NULL,
    `version_number` VARCHAR(50) NULL,
    `release_notes` TEXT NULL,
    `release_date` DATE NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `mpesa_callback_logs` (
    `id` CHAR(36) NOT NULL,
    `transaction_id` CHAR(36) NULL,
    `payload` JSON NOT NULL,
    `received_at` DATETIME NULL
);

CREATE TABLE `mpesa_transactions` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NULL,
    `payment_id` CHAR(36) NULL,
    `gateway_id` CHAR(36) NOT NULL,
    `merchant_request_id` VARCHAR(255) NULL,
    `checkout_request_id` VARCHAR(255) NULL,
    `mpesa_receipt_number` VARCHAR(100) NULL,
    `phone_number` VARCHAR(20) NULL,
    `account_reference` VARCHAR(100) NULL,
    `transaction_desc` TEXT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `transaction_date` DATETIME NULL,
    `result_code` INTEGER NULL,
    `result_description` TEXT NULL,
    `status` VARCHAR(50) NULL,
    `callback_payload` JSON NULL,
    `created_at` DATETIME NULL,
    `processed_by` CHAR(36) NULL,
    `is_reconciled` BOOLEAN NOT NULL,
    `reconciled_at` DATETIME NULL
);

CREATE TABLE `schools` (
    `id` CHAR(36) NOT NULL,
    `school_name` VARCHAR(255) NOT NULL,
    `school_code` VARCHAR(50) NOT NULL,
    `email` VARCHAR(255) NULL,
    `phone` VARCHAR(30) NULL,
    `county` VARCHAR(100) NULL,
    `sub_county` VARCHAR(100) NULL,
    `postal_address` VARCHAR(100) NULL,
    `physical_address` TEXT NULL,
    `logo_url` TEXT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL,
    `updated_at` DATETIME NULL,
    `school_type` VARCHAR(50) NULL,
    `ownership` VARCHAR(50) NULL,
    `registration_number` VARCHAR(100) NULL,
    `kra_pin` VARCHAR(50) NULL,
    `website` VARCHAR(255) NULL,
    `is_deleted` BOOLEAN NULL,
    `deleted_at` DATETIME NULL,
    `deleted_by` CHAR(36) NULL
);

CREATE TABLE `notification_preferences` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `attendance_alerts` BOOLEAN NULL,
    `fee_reminders` BOOLEAN NULL,
    `exam_results` BOOLEAN NULL,
    `discipline_alerts` BOOLEAN NULL,
    `parent_meeting_alerts` BOOLEAN NULL,
    `report_card_alerts` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `notifications` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `is_read` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `online_applications` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `applicant_name` VARCHAR(255) NOT NULL,
    `date_of_birth` DATE NULL,
    `parent_name` VARCHAR(255) NULL,
    `parent_phone` VARCHAR(50) NULL,
    `grade_applied_for` CHAR(36) NULL,
    `status` VARCHAR(30) NULL,
    `submitted_at` DATETIME NULL
);

CREATE TABLE `package_features` (
    `id` CHAR(36) NOT NULL,
    `package_id` CHAR(36) NOT NULL,
    `feature_id` CHAR(36) NOT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `parent_learner_links` (
    `id` CHAR(36) NOT NULL,
    `parent_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `parent_meetings` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `meeting_date` DATETIME NOT NULL,
    `purpose` TEXT NOT NULL,
    `outcome` TEXT NULL,
    `created_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `parents` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NULL,
    `first_name` VARCHAR(100) NOT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(30) NOT NULL,
    `email` VARCHAR(255) NULL,
    `relationship` VARCHAR(50) NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL,
    `is_deleted` BOOLEAN NULL,
    `deleted_at` DATETIME NULL,
    `deleted_by` CHAR(36) NULL
);

CREATE TABLE `pathway_recommendations` (
    `id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `academic_year_id` CHAR(36) NOT NULL,
    `recommendation_date` DATE NULL,
    `recommended_pathway` VARCHAR(100) NULL,
    `confidence_score` DECIMAL(5,2) NULL,
    `strengths` TEXT NULL,
    `improvement_areas` TEXT NULL,
    `generated_by` VARCHAR(50) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `payment_allocations` (
    `id` CHAR(36) NOT NULL,
    `payment_id` CHAR(36) NOT NULL,
    `invoice_id` CHAR(36) NOT NULL,
    `allocated_amount` DECIMAL(12,2) NOT NULL,
    `created_at` DATETIME NULL,
    `school_id` CHAR(36) NULL,
    `created_by` CHAR(36) NULL
);

CREATE TABLE `payment_methods` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `method_name` VARCHAR(100) NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL,
    `is_online` BOOLEAN NOT NULL
);

CREATE TABLE `payment_plan_installments` (
    `id` CHAR(36) NOT NULL,
    `payment_plan_id` CHAR(36) NOT NULL,
    `installment_name` VARCHAR(100) NOT NULL,
    `percentage` DECIMAL(5,2) NOT NULL,
    `due_days` INTEGER NULL,
    `installment_order` INTEGER NOT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `payment_plans` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `plan_name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL,
    `number_of_installments` INTEGER NOT NULL
);

CREATE TABLE `payments` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `invoice_id` CHAR(36) NULL,
    `payment_method_id` CHAR(36) NOT NULL,
    `receipt_number` VARCHAR(100) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `transaction_reference` VARCHAR(255) NULL,
    `payment_date` DATETIME NOT NULL,
    `received_by` CHAR(36) NULL,
    `created_at` DATETIME NULL,
    `payment_status` VARCHAR(20) NULL,
    `reversed` BOOLEAN NULL,
    `reversal_reason` TEXT NULL,
    `reversed_at` DATETIME NULL,
    `reversed_by` CHAR(36) NULL,
    `payer_phone` VARCHAR(20) NULL,
    `payer_name` VARCHAR(255) NULL,
    `remarks` TEXT NULL,
    `posted_by` CHAR(36) NULL,
    `allocated_amount` DECIMAL(12,2) NOT NULL,
    `payment_channel` VARCHAR(255) NULL
);

CREATE TABLE `permissions` (
    `id` CHAR(36) NOT NULL,
    `permission_name` VARCHAR(255) NOT NULL,
    `module_name` VARCHAR(100) NULL,
    `description` TEXT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `platform_audit_logs` (
    `id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NULL,
    `action` VARCHAR(255) NULL,
    `entity_name` VARCHAR(100) NULL,
    `entity_id` CHAR(36) NULL,
    `old_values` JSON NULL,
    `new_values` JSON NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `records_of_work` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `lesson_plan_id` CHAR(36) NOT NULL,
    `date_taught` DATE NOT NULL,
    `content_covered` TEXT NULL,
    `learner_response` TEXT NULL,
    `teacher_reflection` TEXT NULL,
    `status` VARCHAR(50) NULL,
    `created_by` CHAR(36) NOT NULL,
    `created_at` DATETIME NULL,
    `is_deleted` BOOLEAN NOT NULL,
    `deleted_at` DATETIME NULL,
    `deleted_by` CHAR(36) NULL
);

CREATE TABLE `reference_types` (
    `id` CHAR(36) NOT NULL,
    `reference_type` VARCHAR(50) NULL
);

CREATE TABLE `report_card_learning_areas` (
    `id` CHAR(36) NOT NULL,
    `report_card_id` CHAR(36) NOT NULL,
    `learning_area_id` CHAR(36) NOT NULL,
    `score` DECIMAL(6,2) NULL,
    `grade_code` VARCHAR(20) NULL,
    `points` INTEGER NULL,
    `teacher_comment` TEXT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `report_cards` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `exam_id` CHAR(36) NOT NULL,
    `academic_year_id` CHAR(36) NOT NULL,
    `term_id` CHAR(36) NOT NULL,
    `overall_score` DECIMAL(6,2) NULL,
    `overall_grade` VARCHAR(20) NULL,
    `total_points` DECIMAL(6,2) NULL,
    `stream_position` INTEGER NULL,
    `grade_position` INTEGER NULL,
    `school_position` INTEGER NULL,
    `total_learners` INTEGER NULL,
    `attendance_percentage` DECIMAL(5,2) NULL,
    `class_teacher_comment` TEXT NULL,
    `principal_comment` TEXT NULL,
    `pathway_recommendation` TEXT NULL,
    `generated_at` DATETIME NULL
);

CREATE TABLE `report_templates` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `template_name` VARCHAR(255) NOT NULL,
    `education_level_id` CHAR(36) NULL,
    `is_default` BOOLEAN NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `restore_logs` (
    `id` CHAR(36) NOT NULL,
    `restore_request_id` CHAR(36) NOT NULL,
    `action_taken` TEXT NULL,
    `performed_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `restore_requests` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `backup_file_id` CHAR(36) NOT NULL,
    `requested_by` CHAR(36) NULL,
    `status` VARCHAR(30) NULL,
    `requested_at` DATETIME NULL,
    `completed_at` DATETIME NULL
);

CREATE TABLE `role_permissions` (
    `id` CHAR(36) NOT NULL,
    `role_id` CHAR(36) NOT NULL,
    `permission_id` CHAR(36) NOT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `roles` (
    `id` CHAR(36) NOT NULL,
    `role_name` VARCHAR(100) NOT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `room_constraints` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `room_id` CHAR(36) NOT NULL,
    `learning_area_id` CHAR(36) NULL,
    `constraint_type` VARCHAR(100) NOT NULL,
    `constraint_value` VARCHAR(255) NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `room_types` (
    `id` CHAR(36) NOT NULL,
    `type_name` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `rooms` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `room_name` VARCHAR(255) NOT NULL,
    `capacity` INTEGER NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL,
    `room_type_id` CHAR(36) NULL,
    `room_code` VARCHAR(50) NULL,
    `block_name` VARCHAR(100) NULL,
    `floor_number` INTEGER NULL,
    `created_by` CHAR(36) NULL
);

CREATE TABLE `route_stops` (
    `id` CHAR(36) NOT NULL,
    `route_id` CHAR(36) NOT NULL,
    `stop_name` VARCHAR(255) NOT NULL,
    `stop_order` INTEGER NOT NULL,
    `pickup_time` TIME NULL,
    `dropoff_time` TIME NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `saved_reports` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `report_name` VARCHAR(255) NOT NULL,
    `report_type` VARCHAR(100) NULL,
    `report_filters` JSON NULL,
    `created_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `scheme_lessons` (
    `id` CHAR(36) NOT NULL,
    `week_id` CHAR(36) NOT NULL,
    `lesson_number` INTEGER NOT NULL,
    `strand` VARCHAR(255) NOT NULL,
    `sub_strand` VARCHAR(255) NOT NULL,
    `specific_learning_outcome` TEXT NULL,
    `learning_experience` TEXT NULL,
    `resources` TEXT NULL,
    `assessment_method` TEXT NULL,
    `created_at` DATETIME NULL,
    `scheme_id` CHAR(36) NOT NULL,
    `is_deleted` BOOLEAN NOT NULL,
    `deleted_at` DATETIME NULL,
    `deleted_by` CHAR(36) NULL
);

CREATE TABLE `scheme_weeks` (
    `id` CHAR(36) NOT NULL,
    `scheme_id` CHAR(36) NOT NULL,
    `week_number` INTEGER NOT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `schemes_of_work` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learning_area_id` CHAR(36) NOT NULL,
    `grade_id` CHAR(36) NOT NULL,
    `academic_year_id` CHAR(36) NOT NULL,
    `term_id` CHAR(36) NOT NULL,
    `title` VARCHAR(255) NULL,
    `active` BOOLEAN NULL,
    `created_by` CHAR(36) NULL,
    `created_at` DATETIME NULL,
    `is_deleted` BOOLEAN NOT NULL,
    `deleted_at` DATETIME NULL,
    `deleted_by` CHAR(36) NULL
);

CREATE TABLE `school_benchmarks` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `benchmark_group_id` CHAR(36) NOT NULL,
    `metric_id` CHAR(36) NOT NULL,
    `metric_value` DECIMAL(12,2) NULL,
    `ranking` INTEGER NULL,
    `benchmark_date` DATE NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `school_calendar` (
    `calendar_id` INTEGER NOT NULL,
    `event_name` VARCHAR(255) NULL,
    `event_type` VARCHAR(50) NULL,
    `start_date` DATE NULL,
    `end_date` DATE NULL,
    `description` TEXT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `school_group_members` (
    `id` CHAR(36) NOT NULL,
    `group_id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `joined_at` DATETIME NULL
);

CREATE TABLE `school_groups` (
    `id` CHAR(36) NOT NULL,
    `group_name` VARCHAR(255) NOT NULL,
    `registration_number` VARCHAR(100) NULL,
    `contact_email` VARCHAR(255) NULL,
    `contact_phone` VARCHAR(50) NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `school_integrations` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `provider_id` CHAR(36) NOT NULL,
    `configuration` JSON NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `school_learning_areas` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learning_area_id` CHAR(36) NOT NULL,
    `is_examined` BOOLEAN NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `school_module_subscriptions` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `module_id` CHAR(36) NOT NULL,
    `start_date` DATE NULL,
    `expiry_date` DATE NULL,
    `status` VARCHAR(30) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `school_payment_gateways` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `gateway_name` VARCHAR(50) NOT NULL,
    `gateway_type` VARCHAR(50) NOT NULL,
    `shortcode` VARCHAR(50) NULL,
    `paybill_number` VARCHAR(50) NULL,
    `till_number` VARCHAR(50) NULL,
    `account_reference_format` VARCHAR(100) NULL,
    `encrypted_consumer_key` TEXT NULL,
    `encrypted_consumer_secret` TEXT NULL,
    `encrypted_passkey` TEXT NULL,
    `initiator_name` VARCHAR(100) NULL,
    `encrypted_security_credential` TEXT NULL,
    `callback_url` TEXT NULL,
    `validation_url` TEXT NULL,
    `confirmation_url` TEXT NULL,
    `environment` VARCHAR(20) NULL,
    `active` BOOLEAN NULL,
    `is_default` BOOLEAN NULL,
    `created_by` CHAR(36) NULL,
    `updated_by` CHAR(36) NULL,
    `created_at` DATETIME NULL,
    `updated_at` DATETIME NULL
);

CREATE TABLE `school_settings` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `school_motto` VARCHAR(255) NULL,
    `school_vision` TEXT NULL,
    `school_mission` TEXT NULL,
    `school_type` VARCHAR(50) NULL,
    `ownership` VARCHAR(50) NULL,
    `principal_name` VARCHAR(255) NULL,
    `principal_signature_url` TEXT NULL,
    `deputy_principal_name` VARCHAR(255) NULL,
    `school_logo_url` TEXT NULL,
    `report_header` TEXT NULL,
    `report_footer` TEXT NULL,
    `grading_system` VARCHAR(50) NULL,
    `pathway_enabled` BOOLEAN NULL,
    `parent_portal_enabled` BOOLEAN NULL,
    `sms_notifications_enabled` BOOLEAN NULL,
    `email_notifications_enabled` BOOLEAN NULL,
    `attendance_enabled` BOOLEAN NULL,
    `library_enabled` BOOLEAN NULL,
    `inventory_enabled` BOOLEAN NULL,
    `finance_enabled` BOOLEAN NULL,
    `timetable_enabled` BOOLEAN NULL,
    `elections_enabled` BOOLEAN NULL,
    `created_at` DATETIME NULL,
    `updated_at` DATETIME NULL,
    `primary_grading_system` VARCHAR(50) NULL,
    `junior_grading_system` VARCHAR(50) NULL,
    `subscription_package` VARCHAR(100) NULL,
    `subscription_status` VARCHAR(50) NULL,
    `school_health_score_enabled` BOOLEAN NULL
);

CREATE TABLE `school_status_logs` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `old_status` VARCHAR(30) NULL,
    `new_status` VARCHAR(30) NULL,
    `reason` TEXT NULL,
    `changed_by` CHAR(36) NULL,
    `changed_at` DATETIME NULL
);

CREATE TABLE `school_subscriptions` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `package_id` CHAR(36) NULL,
    `start_date` DATE NULL,
    `expiry_date` DATE NULL,
    `grace_end_date` DATE NULL,
    `status` VARCHAR(30) NULL,
    `created_at` DATETIME NULL,
    `source` VARCHAR(30) NULL,
    `activated_by` CHAR(36) NULL,
    `amount_paid` DECIMAL(12,2) NULL,
    `payment_reference` VARCHAR(100) NULL,
    `is_trial` BOOLEAN NULL,
    `auto_renew` BOOLEAN NULL,
    `updated_at` DATETIME NULL,
    `updated_by` CHAR(36) NULL
);

CREATE TABLE `sick_bay_visits` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `visit_date` DATETIME NOT NULL,
    `symptoms` TEXT NULL,
    `diagnosis` TEXT NULL,
    `action_taken` TEXT NULL,
    `attended_by` CHAR(36) NULL,
    `sent_home` BOOLEAN NULL,
    `admitted` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `sms_credit_purchases` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `sms_package_id` CHAR(36) NOT NULL,
    `sms_credits` INTEGER NOT NULL,
    `amount_paid` DECIMAL(12,2) NOT NULL,
    `mpesa_receipt_number` VARCHAR(100) NULL,
    `payer_phone` VARCHAR(20) NULL,
    `verification_status` VARCHAR(30) NULL,
    `verified_by` CHAR(36) NULL,
    `verified_at` DATETIME NULL,
    `purchase_date` DATETIME NULL
);

CREATE TABLE `sms_packages` (
    `id` CHAR(36) NOT NULL,
    `package_name` VARCHAR(100) NOT NULL,
    `sms_count` INTEGER NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `sms_transactions` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `communication_log_id` CHAR(36) NULL,
    `learner_id` CHAR(36) NULL,
    `parent_id` CHAR(36) NULL,
    `phone_number` VARCHAR(20) NOT NULL,
    `sms_units` INTEGER NULL,
    `provider_name` VARCHAR(100) NULL,
    `provider_message_id` VARCHAR(255) NULL,
    `delivery_status` VARCHAR(30) NULL,
    `delivered_at` DATETIME NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `sms_wallets` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `sms_balance` INTEGER NULL,
    `low_balance_threshold` INTEGER NULL,
    `created_at` DATETIME NULL,
    `updated_at` DATETIME NULL,
    `total_purchased` INTEGER NULL,
    `total_consumed` INTEGER NULL,
    `total_amount_spent` DECIMAL(12,2) NULL,
    `status` VARCHAR(20) NULL,
    `last_purchase_date` DATETIME NULL,
    `last_purchase_amount` DECIMAL(12,2) NULL,
    `updated_by` CHAR(36) NULL
);

CREATE TABLE `staff_documents` (
    `id` CHAR(36) NOT NULL,
    `teacher_id` CHAR(36) NOT NULL,
    `document_id` CHAR(36) NOT NULL,
    `document_number` VARCHAR(100) NULL,
    `issue_date` DATE NULL,
    `expiry_date` DATE NULL,
    `created_at` DATETIME NULL,
    `school_id` CHAR(36) NOT NULL,
    `remarks` TEXT NULL,
    `created_by` CHAR(36) NULL,
    `updated_at` DATETIME NULL,
    `updated_by` CHAR(36) NULL
);

CREATE TABLE `stock_adjustments` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `item_id` CHAR(36) NOT NULL,
    `adjustment_type` VARCHAR(30) NULL,
    `quantity` INTEGER NOT NULL,
    `reason` TEXT NULL,
    `adjusted_by` CHAR(36) NULL,
    `adjustment_date` DATE NOT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `stock_issue_items` (
    `id` CHAR(36) NOT NULL,
    `issue_id` CHAR(36) NOT NULL,
    `item_id` CHAR(36) NOT NULL,
    `quantity` INTEGER NOT NULL
);

CREATE TABLE `stock_issues` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `issue_number` VARCHAR(100) NULL,
    `issue_date` DATE NOT NULL,
    `issued_to` VARCHAR(255) NULL,
    `department` VARCHAR(255) NULL,
    `issued_by` CHAR(36) NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `stock_receipt_items` (
    `id` CHAR(36) NOT NULL,
    `receipt_id` CHAR(36) NOT NULL,
    `item_id` CHAR(36) NOT NULL,
    `quantity` INTEGER NOT NULL,
    `unit_cost` DECIMAL(12,2) NULL,
    `total_cost` DECIMAL(12,2) NULL
);

CREATE TABLE `stock_receipts` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `supplier_id` CHAR(36) NULL,
    `receipt_number` VARCHAR(100) NULL,
    `receipt_date` DATE NOT NULL,
    `received_by` CHAR(36) NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `streams` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `grade_id` CHAR(36) NOT NULL,
    `stream_name` VARCHAR(50) NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `subscription_features` (
    `id` CHAR(36) NOT NULL,
    `feature_code` VARCHAR(100) NOT NULL,
    `feature_name` VARCHAR(255) NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `subscription_packages` (
    `id` CHAR(36) NOT NULL,
    `package_name` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `duration_months` INTEGER NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `subscription_payment_requests` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `package_id` CHAR(36) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `request_date` DATETIME NULL,
    `status` VARCHAR(30) NULL,
    `created_at` DATETIME NULL,
    `payment_reference` VARCHAR(100) NULL,
    `payer_phone` VARCHAR(20) NULL,
    `payer_name` VARCHAR(255) NULL,
    `verified` BOOLEAN NULL,
    `verified_at` DATETIME NULL,
    `verified_by` CHAR(36) NULL,
    `subscription_id` CHAR(36) NULL
);

CREATE TABLE `subscription_payments` (
    `id` CHAR(36) NOT NULL,
    `payment_request_id` CHAR(36) NOT NULL,
    `mpesa_receipt_number` VARCHAR(100) NOT NULL,
    `payer_phone` VARCHAR(20) NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `payment_date` DATETIME NULL,
    `verification_status` VARCHAR(30) NULL,
    `verified_by` CHAR(36) NULL,
    `verified_at` DATETIME NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `subscription_upgrade_requests` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `current_package_id` CHAR(36) NULL,
    `requested_package_id` CHAR(36) NOT NULL,
    `additional_amount` DECIMAL(12,2) NULL,
    `status` VARCHAR(30) NULL,
    `approved_by` CHAR(36) NULL,
    `approved_at` DATETIME NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `super_admins` (
    `id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `suppliers` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `supplier_name` VARCHAR(255) NOT NULL,
    `contact_person` VARCHAR(255) NULL,
    `phone` VARCHAR(50) NULL,
    `email` VARCHAR(255) NULL,
    `address` TEXT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `support_ticket_comments` (
    `id` CHAR(36) NOT NULL,
    `ticket_id` CHAR(36) NOT NULL,
    `comment_text` TEXT NOT NULL,
    `commented_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `support_tickets` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `ticket_number` VARCHAR(100) NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `priority` VARCHAR(20) NULL,
    `status` VARCHAR(30) NULL,
    `assigned_to` CHAR(36) NULL,
    `created_by` CHAR(36) NULL,
    `created_at` DATETIME NULL,
    `resolved_at` DATETIME NULL
);

CREATE TABLE `system_announcements` (
    `id` CHAR(36) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `message_body` TEXT NOT NULL,
    `start_date` DATE NULL,
    `end_date` DATE NULL,
    `active` BOOLEAN NULL,
    `created_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `system_settings` (
    `id` CHAR(36) NOT NULL,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT NULL,
    `description` TEXT NULL,
    `updated_by` CHAR(36) NULL,
    `updated_at` DATETIME NULL
);

CREATE TABLE `teacher_assignments` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `teacher_id` CHAR(36) NOT NULL,
    `learning_area_id` CHAR(36) NOT NULL,
    `grade_id` CHAR(36) NOT NULL,
    `stream_id` CHAR(36) NULL,
    `academic_year_id` CHAR(36) NOT NULL,
    `term_id` CHAR(36) NOT NULL,
    `is_class_teacher` BOOLEAN NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL,
    `lessons_per_week` INTEGER NULL,
    `is_deleted` BOOLEAN NOT NULL,
    `deleted_at` DATETIME NULL,
    `deleted_by` CHAR(36) NULL
);

CREATE TABLE `teacher_availability` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `teacher_id` CHAR(36) NOT NULL,
    `day_of_week` INTEGER NOT NULL,
    `period_id` CHAR(36) NOT NULL,
    `is_available` BOOLEAN NULL,
    `remarks` TEXT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `teacher_constraints` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `teacher_id` CHAR(36) NOT NULL,
    `constraint_type` VARCHAR(100) NOT NULL,
    `constraint_value` VARCHAR(255) NULL,
    `priority` VARCHAR(20) NULL,
    `active` BOOLEAN NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `teachers` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `tsc_no` VARCHAR(50) NULL,
    `staff_no` VARCHAR(50) NULL,
    `gender` VARCHAR(20) NULL,
    `designation` VARCHAR(100) NULL,
    `employment_type` VARCHAR(50) NULL,
    `phone` VARCHAR(30) NULL,
    `email` VARCHAR(255) NULL,
    `national_id` VARCHAR(50) NULL,
    `date_joined` DATE NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL,
    `updated_at` DATETIME NULL,
    `is_deleted` BOOLEAN NULL,
    `deleted_at` DATETIME NULL,
    `deleted_by` CHAR(36) NULL
);

CREATE TABLE `terms` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `academic_year_id` CHAR(36) NOT NULL,
    `term_name` VARCHAR(20) NOT NULL,
    `start_date` DATE NULL,
    `end_date` DATE NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `timetable_conflicts` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `timetable_id` CHAR(36) NOT NULL,
    `conflict_type` VARCHAR(100) NOT NULL,
    `severity` VARCHAR(20) NULL,
    `description` TEXT NOT NULL,
    `resolved` BOOLEAN NULL,
    `resolved_at` DATETIME NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `timetable_constraints` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `constraint_name` VARCHAR(255) NOT NULL,
    `constraint_category` VARCHAR(50) NOT NULL,
    `priority` VARCHAR(20) NULL,
    `active` BOOLEAN NULL,
    `description` TEXT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `timetable_entries` (
    `id` CHAR(36) NOT NULL,
    `timetable_id` CHAR(36) NOT NULL,
    `day_of_week` INTEGER NOT NULL,
    `period_id` CHAR(36) NOT NULL,
    `grade_id` CHAR(36) NOT NULL,
    `stream_id` CHAR(36) NOT NULL,
    `learning_area_id` CHAR(36) NOT NULL,
    `teacher_id` CHAR(36) NOT NULL,
    `room_id` CHAR(36) NULL,
    `is_double_lesson` BOOLEAN NULL,
    `remarks` TEXT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `timetable_generation_runs` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `timetable_id` CHAR(36) NOT NULL,
    `generated_by` CHAR(36) NULL,
    `generation_type` VARCHAR(50) NULL,
    `status` VARCHAR(50) NULL,
    `total_entries` INTEGER NULL,
    `total_conflicts` INTEGER NULL,
    `started_at` DATETIME NULL,
    `completed_at` DATETIME NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `timetable_periods` (
    `id` CHAR(36) NOT NULL,
    `timetable_profile_id` CHAR(36) NOT NULL,
    `period_name` VARCHAR(100) NOT NULL,
    `period_order` INTEGER NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `is_teaching_period` BOOLEAN NULL,
    `is_break` BOOLEAN NULL,
    `is_lunch` BOOLEAN NULL,
    `is_assembly` BOOLEAN NULL,
    `is_games` BOOLEAN NULL,
    `is_club` BOOLEAN NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `timetable_profiles` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `profile_name` VARCHAR(255) NOT NULL,
    `education_level_id` CHAR(36) NULL,
    `periods_per_day` INTEGER NOT NULL,
    `periods_per_week` INTEGER NULL,
    `lesson_duration_minutes` INTEGER NOT NULL,
    `allow_double_lessons` BOOLEAN NULL,
    `use_cbc_template` BOOLEAN NULL,
    `active` BOOLEAN NULL,
    `is_default` BOOLEAN NULL,
    `created_at` DATETIME NULL,
    `updated_at` DATETIME NULL
);

CREATE TABLE `timetable_publications` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `timetable_id` CHAR(36) NOT NULL,
    `publication_status` VARCHAR(50) NOT NULL,
    `published_by` CHAR(36) NULL,
    `published_at` DATETIME NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `timetable_substitutions` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `timetable_entry_id` CHAR(36) NOT NULL,
    `absent_teacher_id` CHAR(36) NOT NULL,
    `substitute_teacher_id` CHAR(36) NOT NULL,
    `substitution_date` DATE NOT NULL,
    `reason` TEXT NULL,
    `approved_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `timetables` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `timetable_profile_id` CHAR(36) NOT NULL,
    `academic_year_id` CHAR(36) NOT NULL,
    `term_id` CHAR(36) NOT NULL,
    `timetable_name` VARCHAR(255) NOT NULL,
    `status` VARCHAR(50) NULL,
    `active` BOOLEAN NULL,
    `created_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `transaction_reconciliations` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `mpesa_transaction_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NULL,
    `invoice_id` CHAR(36) NULL,
    `reconciled` BOOLEAN NULL,
    `reconciled_by` CHAR(36) NULL,
    `reconciled_at` DATETIME NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `transport_attendance` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `learner_id` CHAR(36) NOT NULL,
    `attendance_date` DATE NOT NULL,
    `trip_type` VARCHAR(20) NOT NULL,
    `status` VARCHAR(20) NOT NULL,
    `recorded_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `transport_incidents` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `vehicle_id` CHAR(36) NULL,
    `incident_date` DATETIME NOT NULL,
    `incident_type` VARCHAR(100) NULL,
    `description` TEXT NULL,
    `action_taken` TEXT NULL,
    `reported_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `transport_routes` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `route_name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `monthly_fee` DECIMAL(12,2) NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `user_roles` (
    `user_id` CHAR(36) NOT NULL,
    `role_id` CHAR(36) NOT NULL
);

CREATE TABLE `users` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `role_id` CHAR(36) NOT NULL,
    `username` VARCHAR(100) NOT NULL,
    `password_hash` TEXT NOT NULL,
    `email` VARCHAR(255) NULL,
    `phone` VARCHAR(30) NULL,
    `first_login` BOOLEAN NULL,
    `active` BOOLEAN NULL,
    `last_login` DATETIME NULL,
    `created_at` DATETIME NULL,
    `updated_at` DATETIME NULL,
    `first_name` VARCHAR(100) NULL,
    `middle_name` VARCHAR(100) NULL,
    `last_name` VARCHAR(100) NULL,
    `mfa_enabled` BOOLEAN NULL,
    `created_by` CHAR(36) NULL,
    `updated_by` CHAR(36) NULL,
    `is_deleted` BOOLEAN NULL,
    `deleted_at` DATETIME NULL,
    `deleted_by` CHAR(36) NULL,
    `password_changed_at` DATETIME NULL,
    `failed_login_attempts` INTEGER NULL,
    `account_locked_until` DATETIME NULL,
    `password_reset_token` TEXT NULL,
    `password_reset_expires` DATETIME NULL,
    `mfa_secret` TEXT NULL,
    `last_failed_login` DATETIME NULL
);

CREATE TABLE `vehicle_assignments` (
    `id` CHAR(36) NOT NULL,
    `vehicle_id` CHAR(36) NOT NULL,
    `route_id` CHAR(36) NOT NULL,
    `start_date` DATE NULL,
    `end_date` DATE NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `vehicles` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `registration_number` VARCHAR(50) NOT NULL,
    `vehicle_name` VARCHAR(255) NULL,
    `capacity` INTEGER NOT NULL,
    `driver_name` VARCHAR(255) NULL,
    `driver_phone` VARCHAR(50) NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `website_events` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `event_title` VARCHAR(255) NULL,
    `description` TEXT NULL,
    `event_date` DATE NULL,
    `location` VARCHAR(255) NULL,
    `published` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `website_galleries` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `album_name` VARCHAR(255) NULL,
    `description` TEXT NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `website_gallery_images` (
    `id` CHAR(36) NOT NULL,
    `gallery_id` CHAR(36) NOT NULL,
    `image_url` TEXT NOT NULL,
    `caption` TEXT NULL,
    `uploaded_at` DATETIME NULL
);

CREATE TABLE `website_news` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `summary` TEXT NULL,
    `content` TEXT NULL,
    `featured_image` TEXT NULL,
    `published` BOOLEAN NULL,
    `published_at` DATETIME NULL,
    `created_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `website_pages` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `page_title` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(255) NOT NULL,
    `page_content` TEXT NULL,
    `published` BOOLEAN NULL,
    `created_by` CHAR(36) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `website_settings` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `website_title` VARCHAR(255) NULL,
    `website_subtitle` TEXT NULL,
    `domain_name` VARCHAR(255) NULL,
    `logo_url` TEXT NULL,
    `contact_email` VARCHAR(255) NULL,
    `contact_phone` VARCHAR(50) NULL,
    `address` TEXT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `white_label_brands` (
    `id` CHAR(36) NOT NULL,
    `partner_id` CHAR(36) NOT NULL,
    `brand_name` VARCHAR(255) NOT NULL,
    `logo_url` TEXT NULL,
    `primary_color` VARCHAR(20) NULL,
    `secondary_color` VARCHAR(20) NULL,
    `support_email` VARCHAR(255) NULL,
    `support_phone` VARCHAR(50) NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `workflow_actions` (
    `id` CHAR(36) NOT NULL,
    `instance_id` CHAR(36) NOT NULL,
    `action_by` CHAR(36) NOT NULL,
    `action_type` VARCHAR(30) NULL,
    `comments` TEXT NULL,
    `action_date` DATETIME NULL
);

CREATE TABLE `workflow_definitions` (
    `id` CHAR(36) NOT NULL,
    `school_id` CHAR(36) NOT NULL,
    `workflow_name` VARCHAR(255) NOT NULL,
    `entity_type` VARCHAR(100) NOT NULL,
    `active` BOOLEAN NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `workflow_instances` (
    `id` CHAR(36) NOT NULL,
    `workflow_id` CHAR(36) NOT NULL,
    `entity_id` CHAR(36) NOT NULL,
    `entity_type` VARCHAR(100) NOT NULL,
    `current_step` INTEGER NULL,
    `status` VARCHAR(30) NULL,
    `created_at` DATETIME NULL
);

CREATE TABLE `workflow_steps` (
    `id` CHAR(36) NOT NULL,
    `workflow_id` CHAR(36) NOT NULL,
    `step_order` INTEGER NOT NULL,
    `role_id` CHAR(36) NOT NULL,
    `step_name` VARCHAR(255) NULL
);
