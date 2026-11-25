CREATE TABLE IF NOT EXISTS credentials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    enrollment_id BIGINT UNSIGNED NOT NULL,
    serial CHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    issuer_name VARCHAR(160) NOT NULL,
    holder_name VARCHAR(160) NOT NULL,
    course_title VARCHAR(200) NOT NULL,
    course_revision INT UNSIGNED NOT NULL,
    issued_by BIGINT UNSIGNED NOT NULL,
    issued_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    valid_days SMALLINT UNSIGNED NOT NULL,
    evidence JSON NOT NULL,
    revoked_at DATETIME(6) NULL,
    revoked_by BIGINT UNSIGNED NULL,
    revocation_reason VARCHAR(1000) NULL,
    UNIQUE KEY credential_scope (tenant_id, id),
    UNIQUE KEY credential_enrollment (tenant_id, enrollment_id),
    UNIQUE KEY credential_serial (serial),
    KEY credential_due (revoked_at, expires_at, id),
    FOREIGN KEY (tenant_id, enrollment_id) REFERENCES enrollments (tenant_id, id),
    FOREIGN KEY (tenant_id, issued_by) REFERENCES accounts (tenant_id, id),
    FOREIGN KEY (tenant_id, revoked_by) REFERENCES accounts (tenant_id, id),
    CHECK (expires_at > issued_at AND valid_days BETWEEN 1 AND 3650),
    CHECK ((revoked_at IS NULL AND revoked_by IS NULL AND revocation_reason IS NULL) OR
           (revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND revocation_reason IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS retraining_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    credential_id BIGINT UNSIGNED NOT NULL,
    cycle SMALLINT UNSIGNED NOT NULL,
    state ENUM('pending','enrolled','completed','cancelled') NOT NULL DEFAULT 'pending',
    due_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    target_enrollment_id BIGINT UNSIGNED NULL,
    renewed_credential_id BIGINT UNSIGNED NULL,
    finished_at DATETIME(6) NULL,
    UNIQUE KEY retraining_scope (tenant_id, id),
    UNIQUE KEY retraining_cycle (tenant_id, credential_id, cycle),
    UNIQUE KEY retraining_target (tenant_id, target_enrollment_id),
    UNIQUE KEY retraining_renewal (tenant_id, renewed_credential_id),
    KEY retraining_status (tenant_id, state, id),
    FOREIGN KEY (tenant_id, credential_id) REFERENCES credentials (tenant_id, id),
    FOREIGN KEY (tenant_id, target_enrollment_id) REFERENCES enrollments (tenant_id, id),
    FOREIGN KEY (tenant_id, renewed_credential_id) REFERENCES credentials (tenant_id, id),
    CHECK (cycle > 0),
    CHECK ((state='pending' AND target_enrollment_id IS NULL AND renewed_credential_id IS NULL AND finished_at IS NULL) OR
           (state='enrolled' AND target_enrollment_id IS NOT NULL AND renewed_credential_id IS NULL AND finished_at IS NULL) OR
           (state='completed' AND target_enrollment_id IS NOT NULL AND renewed_credential_id IS NOT NULL AND finished_at IS NOT NULL) OR
           (state='cancelled' AND renewed_credential_id IS NULL AND finished_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
