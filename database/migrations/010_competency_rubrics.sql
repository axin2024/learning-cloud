CREATE TABLE IF NOT EXISTS competency_standards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    latest_revision INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY standard_code (tenant_id,code),
    UNIQUE KEY standard_scope (tenant_id,id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rubric_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    standard_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    level_name VARCHAR(80) NOT NULL,
    passing_score TINYINT UNSIGNED NOT NULL,
    arbitration_gap TINYINT UNSIGNED NOT NULL,
    published_by BIGINT UNSIGNED NOT NULL,
    published_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY rubric_scope (tenant_id,id),
    UNIQUE KEY standard_revision (tenant_id,standard_id,revision),
    FOREIGN KEY (tenant_id,standard_id) REFERENCES competency_standards(tenant_id,id),
    FOREIGN KEY (tenant_id,published_by) REFERENCES accounts(tenant_id,id),
    CHECK (passing_score<=100 AND arbitration_gap<=100)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rubric_dimensions (
    tenant_id BIGINT UNSIGNED NOT NULL,
    rubric_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    parent_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ordinal SMALLINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    weight_pct TINYINT UNSIGNED NOT NULL,
    minimum_score TINYINT UNSIGNED NOT NULL,
    mandatory BOOLEAN NOT NULL,
    evidence_kind ENUM('document','image','video') NOT NULL,
    evidence_scope ENUM('personal','team') NOT NULL,
    evidence_requirement VARCHAR(500) NOT NULL,
    PRIMARY KEY (tenant_id,rubric_id,code),
    UNIQUE KEY rubric_order (tenant_id,rubric_id,ordinal),
    FOREIGN KEY (tenant_id,rubric_id) REFERENCES rubric_revisions(tenant_id,id),
    FOREIGN KEY (tenant_id,rubric_id,parent_code) REFERENCES rubric_dimensions(tenant_id,rubric_id,code),
    CHECK (weight_pct<=100 AND minimum_score<=100)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cohort_rubrics (
    tenant_id BIGINT UNSIGNED NOT NULL,
    cohort_id BIGINT UNSIGNED NOT NULL,
    rubric_id BIGINT UNSIGNED NOT NULL,
    bound_by BIGINT UNSIGNED NOT NULL,
    bound_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant_id,cohort_id),
    FOREIGN KEY (tenant_id,cohort_id) REFERENCES cohorts(tenant_id,id),
    FOREIGN KEY (tenant_id,rubric_id) REFERENCES rubric_revisions(tenant_id,id),
    FOREIGN KEY (tenant_id,bound_by) REFERENCES accounts(tenant_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS practicum_receipts (
    tenant_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    operation VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result JSON NULL,
    PRIMARY KEY (tenant_id,actor_id,operation,request_key),
    FOREIGN KEY (tenant_id,actor_id) REFERENCES accounts(tenant_id,id)
) ENGINE=InnoDB;
