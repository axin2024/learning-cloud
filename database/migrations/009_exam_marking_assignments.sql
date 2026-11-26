CREATE TABLE IF NOT EXISTS exam_marking_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    attempt_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    assigned_to BIGINT UNSIGNED NOT NULL,
    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    closed_at DATETIME(6) NULL,
    closed_reason ENUM('replaced','revoked','completed') NULL,
    active_attempt_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(closed_at IS NULL, attempt_id, NULL)) STORED,
    UNIQUE KEY exam_marking_active (tenant_id, active_attempt_id),
    KEY exam_marking_history (tenant_id, attempt_id, id),
    FOREIGN KEY (tenant_id, attempt_id) REFERENCES exam_attempts (tenant_id, id),
    FOREIGN KEY (tenant_id, assigned_by) REFERENCES accounts (tenant_id, id),
    FOREIGN KEY (tenant_id, assigned_to) REFERENCES accounts (tenant_id, id),
    CHECK (assigned_by <> assigned_to AND ((closed_at IS NULL) = (closed_reason IS NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
