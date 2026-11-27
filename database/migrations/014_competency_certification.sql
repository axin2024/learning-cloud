CREATE TABLE IF NOT EXISTS competency_appeals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    case_id BIGINT UNSIGNED NOT NULL,
    assignment_id BIGINT UNSIGNED NOT NULL,
    dimension_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    evidence_submission_id BIGINT UNSIGNED NOT NULL,
    statement VARCHAR(1600) NOT NULL,
    opened_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    status ENUM('pending','upheld','amended') NOT NULL DEFAULT 'pending',
    active_assignment BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status='pending',assignment_id,NULL)) STORED,
    decided_by BIGINT UNSIGNED NULL,
    decided_at DATETIME(6) NULL,
    reason VARCHAR(1600) NULL,
    previous_score DECIMAL(4,1) NOT NULL,
    decided_score DECIMAL(4,1) NULL,
    clears_evidence_gap BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE KEY pending_appeal (tenant_id,active_assignment),
    KEY appeal_history (tenant_id,case_id,id),
    FOREIGN KEY (tenant_id,case_id) REFERENCES practicum_review_cases(tenant_id,id),
    FOREIGN KEY (evidence_submission_id) REFERENCES practicum_submissions(id),
    FOREIGN KEY (assignment_id) REFERENCES practicum_assignments(id),
    FOREIGN KEY (tenant_id,decided_by) REFERENCES accounts(tenant_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS competency_credentials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    assignment_id BIGINT UNSIGNED NOT NULL,
    case_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    serial CHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    total DECIMAL(5,1) NOT NULL,
    issued_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    issued_by BIGINT UNSIGNED NOT NULL,
    replaces_id BIGINT UNSIGNED NULL,
    appeal_id BIGINT UNSIGNED NULL,
    revoked_at DATETIME(6) NULL,
    revoked_by BIGINT UNSIGNED NULL,
    revoked_appeal_id BIGINT UNSIGNED NULL,
    revocation_reason VARCHAR(500) NULL,
    UNIQUE KEY public_number (serial),
    UNIQUE KEY assignment_revision (tenant_id,assignment_id,revision),
    UNIQUE KEY one_successor (replaces_id),
    FOREIGN KEY (tenant_id,case_id) REFERENCES practicum_review_cases(tenant_id,id),
    FOREIGN KEY (assignment_id) REFERENCES practicum_assignments(id),
    FOREIGN KEY (replaces_id) REFERENCES competency_credentials(id),
    FOREIGN KEY (appeal_id) REFERENCES competency_appeals(id),
    FOREIGN KEY (revoked_appeal_id) REFERENCES competency_appeals(id),
    FOREIGN KEY (tenant_id,issued_by) REFERENCES accounts(tenant_id,id),
    FOREIGN KEY (tenant_id,revoked_by) REFERENCES accounts(tenant_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS competency_disclosures (
    assignment_id BIGINT UNSIGNED PRIMARY KEY,
    show_title BOOLEAN NOT NULL,
    show_score BOOLEAN NOT NULL,
    FOREIGN KEY (assignment_id) REFERENCES practicum_assignments(id)
) ENGINE=InnoDB;
