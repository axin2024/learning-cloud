CREATE TABLE IF NOT EXISTS course_equivalences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    source_revision_id BIGINT UNSIGNED NOT NULL,
    target_revision_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    enabled BOOLEAN NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    mappings JSON NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY equivalence_scope (tenant_id, id),
    UNIQUE KEY equivalence_pair (tenant_id, source_revision_id, target_revision_id),
    FOREIGN KEY (tenant_id, source_revision_id) REFERENCES course_revisions (tenant_id, id),
    FOREIGN KEY (tenant_id, target_revision_id) REFERENCES course_revisions (tenant_id, id),
    FOREIGN KEY (tenant_id, updated_by) REFERENCES accounts (tenant_id, id),
    CHECK (source_revision_id <> target_revision_id),
    CHECK (JSON_TYPE(mappings) = 'ARRAY' AND JSON_LENGTH(mappings) BETWEEN 1 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS enrollment_transfers (
    tenant_id BIGINT UNSIGNED NOT NULL,
    source_enrollment_id BIGINT UNSIGNED NOT NULL,
    target_enrollment_id BIGINT UNSIGNED NOT NULL,
    equivalence_id BIGINT UNSIGNED NULL,
    equivalence_snapshot JSON NULL,
    grade_snapshot JSON NOT NULL,
    credited_seconds INT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant_id, source_enrollment_id),
    UNIQUE KEY transfer_target (tenant_id, target_enrollment_id),
    FOREIGN KEY (tenant_id, source_enrollment_id) REFERENCES enrollments (tenant_id, id),
    FOREIGN KEY (tenant_id, target_enrollment_id) REFERENCES enrollments (tenant_id, id),
    FOREIGN KEY (tenant_id, equivalence_id) REFERENCES course_equivalences (tenant_id, id),
    CHECK ((equivalence_id IS NULL) = (equivalence_snapshot IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
