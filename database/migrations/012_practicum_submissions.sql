CREATE TABLE IF NOT EXISTS practicum_artifacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    practicum_id BIGINT UNSIGNED NOT NULL,
    team_id BIGINT UNSIGNED NOT NULL,
    assignment_id BIGINT UNSIGNED NOT NULL,
    dimension_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind ENUM('document','image','video') NOT NULL,
    anchor JSON NOT NULL,
    content MEDIUMBLOB NOT NULL,
    KEY team_digest (tenant_id,practicum_id,team_id,digest),
    FOREIGN KEY (tenant_id,practicum_id,team_id) REFERENCES practicum_teams(tenant_id,practicum_id,id),
    FOREIGN KEY (assignment_id) REFERENCES practicum_assignments(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS practicum_submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    practicum_id BIGINT UNSIGNED NOT NULL,
    team_id BIGINT UNSIGNED NOT NULL,
    attempt INT UNSIGNED NOT NULL,
    submitted_by BIGINT UNSIGNED NOT NULL,
    submitted_at DATETIME(6) NOT NULL,
    late BOOLEAN NOT NULL,
    extension_id BIGINT UNSIGNED NULL,
    rubric_id BIGINT UNSIGNED NOT NULL,
    evidence_manifest JSON NOT NULL,
    manifest_digest CHAR(64) NOT NULL,
    missing_evidence JSON NOT NULL,
    UNIQUE KEY team_attempt (tenant_id,practicum_id,team_id,attempt),
    FOREIGN KEY (tenant_id,practicum_id,team_id) REFERENCES practicum_teams(tenant_id,practicum_id,id),
    FOREIGN KEY (tenant_id,submitted_by) REFERENCES accounts(tenant_id,id),
    FOREIGN KEY (tenant_id,rubric_id) REFERENCES rubric_revisions(tenant_id,id),
    FOREIGN KEY (extension_id) REFERENCES practicum_extensions(id)
) ENGINE=InnoDB;
