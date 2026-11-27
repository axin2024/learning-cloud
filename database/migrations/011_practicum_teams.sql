CREATE TABLE IF NOT EXISTS practicums (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    cohort_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    instructions TEXT NOT NULL,
    sample TEXT NOT NULL,
    due_at DATETIME(6) NOT NULL,
    brief_rev INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY task_scope (tenant_id,id),
    FOREIGN KEY (tenant_id,cohort_id) REFERENCES cohort_rubrics(tenant_id,cohort_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS practicum_teams (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    practicum_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    UNIQUE KEY team_scope (tenant_id,practicum_id,id),
    FOREIGN KEY (tenant_id,practicum_id) REFERENCES practicums(tenant_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS practicum_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    practicum_id BIGINT UNSIGNED NOT NULL,
    team_id BIGINT UNSIGNED NOT NULL,
    learner_id BIGINT UNSIGNED NOT NULL,
    responsibility VARCHAR(160) NOT NULL,
    material TEXT NOT NULL,
    UNIQUE KEY one_assignment (tenant_id,practicum_id,learner_id),
    FOREIGN KEY (tenant_id,practicum_id,team_id) REFERENCES practicum_teams(tenant_id,practicum_id,id),
    FOREIGN KEY (tenant_id,learner_id) REFERENCES accounts(tenant_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS practicum_contributions (
    assignment_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (assignment_id,revision),
    FOREIGN KEY (assignment_id) REFERENCES practicum_assignments(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS practicum_extensions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    practicum_id BIGINT UNSIGNED NOT NULL,
    team_id BIGINT UNSIGNED NOT NULL,
    attempt INT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    until_at DATETIME(6) NULL,
    UNIQUE KEY team_extension (tenant_id,practicum_id,team_id,attempt),
    FOREIGN KEY (tenant_id,practicum_id,team_id) REFERENCES practicum_teams(tenant_id,practicum_id,id),
    FOREIGN KEY (tenant_id,requested_by) REFERENCES accounts(tenant_id,id),
    FOREIGN KEY (tenant_id,approved_by) REFERENCES accounts(tenant_id,id)
) ENGINE=InnoDB;
