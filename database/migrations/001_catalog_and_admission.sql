CREATE TABLE IF NOT EXISTS tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(160) NOT NULL,
    password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY account_scope (tenant_id, id),
    UNIQUE KEY account_email (tenant_id, email),
    FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS account_roles (
    tenant_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    role ENUM('admin','registrar','instructor','learner') NOT NULL,
    PRIMARY KEY (tenant_id, account_id, role),
    FOREIGN KEY (tenant_id, account_id) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS courses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY course_scope (tenant_id, id),
    UNIQUE KEY course_slug (tenant_id, slug),
    FOREIGN KEY (tenant_id, owner_id) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS course_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    edit_version INT UNSIGNED NOT NULL DEFAULT 1,
    title VARCHAR(200) NOT NULL,
    summary TEXT NOT NULL,
    minimum_study_seconds INT UNSIGNED NOT NULL,
    pass_score TINYINT UNSIGNED NOT NULL,
    retake_mode ENUM('highest','latest') NOT NULL,
    published_at TIMESTAMP(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY revision_scope (tenant_id, id),
    UNIQUE KEY course_revision (tenant_id, course_id, revision),
    FOREIGN KEY (tenant_id, course_id) REFERENCES courses (tenant_id, id),
    CHECK (pass_score <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS chapters (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    revision_id BIGINT UNSIGNED NOT NULL,
    ordinal SMALLINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    kind ENUM('text','recorded','live') NOT NULL,
    content MEDIUMTEXT NOT NULL,
    duration_seconds INT UNSIGNED NOT NULL,
    required_seconds INT UNSIGNED NOT NULL,
    rights_basis ENUM('unverified','original','licensed','public_domain') NOT NULL,
    rights_reference VARCHAR(500) NOT NULL,
    UNIQUE KEY chapter_scope (tenant_id, id),
    UNIQUE KEY chapter_order (tenant_id, revision_id, ordinal),
    FOREIGN KEY (tenant_id, revision_id) REFERENCES course_revisions (tenant_id, id),
    CHECK (required_seconds <= duration_seconds)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS cohorts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    revision_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(160) NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    capacity INT UNSIGNED NOT NULL,
    registration_open BOOLEAN NOT NULL DEFAULT TRUE,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY cohort_scope (tenant_id, id),
    UNIQUE KEY cohort_code (tenant_id, code),
    FOREIGN KEY (tenant_id, revision_id) REFERENCES course_revisions (tenant_id, id),
    CHECK (capacity > 0),
    CHECK (ends_on >= starts_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS enrollments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    cohort_id BIGINT UNSIGNED NOT NULL,
    learner_id BIGINT UNSIGNED NOT NULL,
    state ENUM('waitlisted','reserved','enrolled','studying','completed','withdrawn','transferred') NOT NULL,
    active_learner_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CASE WHEN state NOT IN ('withdrawn','transferred') THEN learner_id ELSE NULL END
    ) STORED,
    transferred_from_id BIGINT UNSIGNED NULL,
    enrolled_at TIMESTAMP(6) NULL,
    closed_at TIMESTAMP(6) NULL,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY enrollment_scope (tenant_id, id),
    UNIQUE KEY active_enrollment (tenant_id, cohort_id, active_learner_id),
    UNIQUE KEY one_transfer (tenant_id, transferred_from_id),
    KEY cohort_state (tenant_id, cohort_id, state, id),
    KEY learner_enrollment (tenant_id, learner_id, id),
    FOREIGN KEY (tenant_id, cohort_id) REFERENCES cohorts (tenant_id, id),
    FOREIGN KEY (tenant_id, learner_id) REFERENCES accounts (tenant_id, id),
    FOREIGN KEY (tenant_id, transferred_from_id) REFERENCES enrollments (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS enrollment_requests (
    tenant_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    cohort_id BIGINT UNSIGNED NOT NULL,
    request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    learner_id BIGINT UNSIGNED NOT NULL,
    enrollment_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant_id, actor_id, cohort_id, request_key),
    FOREIGN KEY (tenant_id, actor_id) REFERENCES accounts (tenant_id, id),
    FOREIGN KEY (tenant_id, cohort_id) REFERENCES cohorts (tenant_id, id),
    FOREIGN KEY (tenant_id, learner_id) REFERENCES accounts (tenant_id, id),
    FOREIGN KEY (tenant_id, enrollment_id) REFERENCES enrollments (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS audit_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    object_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    object_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    details JSON NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY object_timeline (tenant_id, object_type, object_id, id),
    FOREIGN KEY (tenant_id, actor_id) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
