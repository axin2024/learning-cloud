CREATE TABLE IF NOT EXISTS cohort_staff (
    tenant_id BIGINT UNSIGNED NOT NULL,
    cohort_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    duty ENUM('instructor','assistant') NOT NULL,
    PRIMARY KEY (tenant_id, cohort_id, account_id),
    KEY teaching_cohorts (tenant_id, account_id, cohort_id),
    FOREIGN KEY (tenant_id, cohort_id) REFERENCES cohorts (tenant_id, id),
    FOREIGN KEY (tenant_id, account_id) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS coursework_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    cohort_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    instructions MEDIUMTEXT NOT NULL,
    due_at DATETIME(6) NOT NULL,
    grace_minutes SMALLINT UNSIGNED NOT NULL,
    required_for_completion BOOLEAN NOT NULL,
    pass_score TINYINT UNSIGNED NOT NULL,
    rubric JSON NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    published_at DATETIME(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY assignment_scope (tenant_id, id),
    KEY cohort_coursework (tenant_id, cohort_id, id),
    FOREIGN KEY (tenant_id, cohort_id) REFERENCES cohorts (tenant_id, id),
    CHECK (pass_score <= 100),
    CHECK (grace_minutes <= 10080)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS coursework_submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    assignment_id BIGINT UNSIGNED NOT NULL,
    enrollment_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    answer MEDIUMTEXT NOT NULL,
    late BOOLEAN NOT NULL,
    request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    submitted_at DATETIME(6) NOT NULL,
    UNIQUE KEY coursework_submission_scope (tenant_id, id),
    UNIQUE KEY coursework_revision (tenant_id, assignment_id, enrollment_id, revision),
    UNIQUE KEY coursework_submission_key (tenant_id, assignment_id, enrollment_id, request_key),
    KEY coursework_by_enrollment (tenant_id, enrollment_id, assignment_id, revision),
    FOREIGN KEY (tenant_id, assignment_id) REFERENCES coursework_assignments (tenant_id, id),
    FOREIGN KEY (tenant_id, enrollment_id) REFERENCES enrollments (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS coursework_grades (
    tenant_id BIGINT UNSIGNED NOT NULL,
    submission_id BIGINT UNSIGNED NOT NULL,
    marker_id BIGINT UNSIGNED NOT NULL,
    score TINYINT UNSIGNED NOT NULL,
    feedback TEXT NOT NULL,
    rubric_marks JSON NOT NULL,
    annotations JSON NOT NULL,
    request_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    graded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant_id, submission_id),
    FOREIGN KEY (tenant_id, submission_id) REFERENCES coursework_submissions (tenant_id, id),
    FOREIGN KEY (tenant_id, marker_id) REFERENCES accounts (tenant_id, id),
    CHECK (score <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS coursework_returns (
    tenant_id BIGINT UNSIGNED NOT NULL,
    submission_id BIGINT UNSIGNED NOT NULL,
    marker_id BIGINT UNSIGNED NOT NULL,
    note TEXT NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    granted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    consumed_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (tenant_id, submission_id),
    UNIQUE KEY coursework_return_used_once (tenant_id, consumed_by),
    FOREIGN KEY (tenant_id, submission_id) REFERENCES coursework_submissions (tenant_id, id),
    FOREIGN KEY (tenant_id, marker_id) REFERENCES accounts (tenant_id, id),
    FOREIGN KEY (tenant_id, consumed_by) REFERENCES coursework_submissions (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
