CREATE TABLE IF NOT EXISTS chapter_media (
    tenant_id BIGINT UNSIGNED NOT NULL,
    chapter_id BIGINT UNSIGNED NOT NULL,
    media_url VARCHAR(2048) NOT NULL,
    PRIMARY KEY (tenant_id, chapter_id),
    FOREIGN KEY (tenant_id, chapter_id) REFERENCES chapters (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS attendance_providers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(160) NOT NULL,
    secret_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    rotated_at TIMESTAMP(6) NULL,
    UNIQUE KEY attendance_provider_scope (tenant_id, id),
    UNIQUE KEY attendance_provider_code (tenant_id, code),
    UNIQUE KEY attendance_provider_token (secret_hash),
    FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS study_live_bindings (
    tenant_id BIGINT UNSIGNED NOT NULL,
    cohort_id BIGINT UNSIGNED NOT NULL,
    chapter_id BIGINT UNSIGNED NOT NULL,
    provider_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant_id, cohort_id, chapter_id),
    FOREIGN KEY (tenant_id, cohort_id) REFERENCES cohorts (tenant_id, id),
    FOREIGN KEY (tenant_id, chapter_id) REFERENCES chapters (tenant_id, id),
    FOREIGN KEY (tenant_id, provider_id) REFERENCES attendance_providers (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS study_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    enrollment_id BIGINT UNSIGNED NOT NULL,
    chapter_id BIGINT UNSIGNED NOT NULL,
    provider_id BIGINT UNSIGNED NULL,
    resume_code CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY study_session_scope (tenant_id, id),
    UNIQUE KEY study_resume_code (tenant_id, resume_code),
    UNIQUE KEY study_chapter_session (tenant_id, enrollment_id, chapter_id),
    KEY chapter_study_sessions (tenant_id, chapter_id, enrollment_id),
    FOREIGN KEY (tenant_id, enrollment_id) REFERENCES enrollments (tenant_id, id),
    FOREIGN KEY (tenant_id, chapter_id) REFERENCES chapters (tenant_id, id),
    FOREIGN KEY (tenant_id, provider_id) REFERENCES attendance_providers (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS study_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NULL,
    provider_id BIGINT UNSIGNED NULL,
    external_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_role ENUM('learner','instructor','host') NULL,
    submitted_code CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL,
    request_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    start_second INT UNSIGNED NOT NULL,
    end_second INT UNSIGNED NOT NULL,
    state ENUM('accepted','review','rejected') NOT NULL,
    reason VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at TIMESTAMP(6) NULL,
    review_note VARCHAR(1000) NULL,
    received_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY session_event (tenant_id, session_id, external_id),
    UNIQUE KEY provider_event (tenant_id, provider_id, external_id),
    KEY attendance_review_queue (tenant_id, state, id),
    FOREIGN KEY (tenant_id, session_id) REFERENCES study_sessions (tenant_id, id),
    FOREIGN KEY (tenant_id, provider_id) REFERENCES attendance_providers (tenant_id, id),
    FOREIGN KEY (tenant_id, reviewed_by) REFERENCES accounts (tenant_id, id),
    CHECK (end_second > start_second),
    CHECK (session_id IS NOT NULL OR provider_id IS NOT NULL),
    CHECK ((provider_id IS NULL) = (source_role IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS study_spans (
    tenant_id BIGINT UNSIGNED NOT NULL,
    enrollment_id BIGINT UNSIGNED NOT NULL,
    chapter_id BIGINT UNSIGNED NOT NULL,
    start_second INT UNSIGNED NOT NULL,
    end_second INT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant_id, enrollment_id, chapter_id, start_second),
    FOREIGN KEY (tenant_id, enrollment_id) REFERENCES enrollments (tenant_id, id),
    FOREIGN KEY (tenant_id, chapter_id) REFERENCES chapters (tenant_id, id),
    CHECK (end_second > start_second)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
