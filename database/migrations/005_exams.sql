CREATE TABLE IF NOT EXISTS exam_papers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    edit_version INT UNSIGNED NOT NULL DEFAULT 1,
    title VARCHAR(200) NOT NULL,
    pools JSON NOT NULL,
    published_at DATETIME(6) NULL,
    UNIQUE KEY exam_paper_scope (tenant_id, id),
    UNIQUE KEY exam_paper_revision (tenant_id, course_id, revision),
    FOREIGN KEY (tenant_id, course_id) REFERENCES courses (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS exam_sittings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    cohort_id BIGINT UNSIGNED NOT NULL,
    paper_id BIGINT UNSIGNED NOT NULL,
    sequence SMALLINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    opens_at DATETIME(6) NOT NULL,
    closes_at DATETIME(6) NOT NULL,
    duration_seconds INT UNSIGNED NOT NULL,
    pass_score TINYINT UNSIGNED NOT NULL,
    retake_mode ENUM('highest','latest') NOT NULL,
    UNIQUE KEY exam_sitting_scope (tenant_id, id),
    UNIQUE KEY exam_sitting_sequence (tenant_id, cohort_id, sequence),
    FOREIGN KEY (tenant_id, cohort_id) REFERENCES cohorts (tenant_id, id),
    FOREIGN KEY (tenant_id, paper_id) REFERENCES exam_papers (tenant_id, id),
    CHECK (opens_at < closes_at AND duration_seconds > 0 AND pass_score <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS exam_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    enrollment_id BIGINT UNSIGNED NOT NULL,
    sitting_id BIGINT UNSIGNED NOT NULL,
    seed CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    started_at DATETIME(6) NOT NULL,
    deadline_at DATETIME(6) NOT NULL,
    answer_version INT UNSIGNED NOT NULL DEFAULT 0,
    save_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    submitted_at DATETIME(6) NULL,
    submission_mode ENUM('manual','expired') NULL,
    score TINYINT UNSIGNED NULL,
    graded_at DATETIME(6) NULL,
    marker_id BIGINT UNSIGNED NULL,
    grade_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    UNIQUE KEY exam_attempt_scope (tenant_id, id),
    UNIQUE KEY exam_sitting_attempt (tenant_id, enrollment_id, sitting_id),
    KEY exam_sitting_roster (tenant_id, sitting_id, id),
    KEY exam_due_attempt (submitted_at, deadline_at, id),
    FOREIGN KEY (tenant_id, enrollment_id) REFERENCES enrollments (tenant_id, id),
    FOREIGN KEY (tenant_id, sitting_id) REFERENCES exam_sittings (tenant_id, id),
    FOREIGN KEY (tenant_id, marker_id) REFERENCES accounts (tenant_id, id),
    CHECK (started_at < deadline_at AND (score IS NULL OR score <= 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS exam_items (
    tenant_id BIGINT UNSIGNED NOT NULL,
    attempt_id BIGINT UNSIGNED NOT NULL,
    ordinal SMALLINT UNSIGNED NOT NULL,
    kind ENUM('single','multiple','written') NOT NULL,
    prompt TEXT NOT NULL,
    options_json JSON NULL,
    guidance JSON NOT NULL,
    points TINYINT UNSIGNED NOT NULL,
    answer JSON NULL,
    awarded TINYINT UNSIGNED NULL,
    feedback TEXT NULL,
    PRIMARY KEY (tenant_id, attempt_id, ordinal),
    FOREIGN KEY (tenant_id, attempt_id) REFERENCES exam_attempts (tenant_id, id),
    CHECK (points > 0 AND points <= 100 AND (awarded IS NULL OR awarded <= points))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
