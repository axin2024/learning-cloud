CREATE TABLE IF NOT EXISTS practicum_review_cases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    submission_id BIGINT UNSIGNED NOT NULL,
    assignment_id BIGINT UNSIGNED NOT NULL,
    prepared_by BIGINT UNSIGNED NOT NULL,
    blind_packet JSON NOT NULL,
    status ENUM('reviewing','arbitration','scored') NOT NULL DEFAULT 'reviewing',
    disputed JSON NULL,
    final_scores JSON NULL,
    weighted_score DECIMAL(5,1) NULL,
    meets_standard BOOLEAN NULL,
    UNIQUE KEY one_review_case (tenant_id,submission_id,assignment_id),
    UNIQUE KEY review_case_scope (tenant_id,id),
    FOREIGN KEY (submission_id) REFERENCES practicum_submissions(id),
    FOREIGN KEY (assignment_id) REFERENCES practicum_assignments(id),
    FOREIGN KEY (tenant_id,prepared_by) REFERENCES accounts(tenant_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS practicum_review_seats (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    case_id BIGINT UNSIGNED NOT NULL,
    seat ENUM('first','second','arbiter') NOT NULL,
    reviewer_id BIGINT UNSIGNED NOT NULL,
    scores JSON NULL,
    locked_at DATETIME(6) NULL,
    UNIQUE KEY review_seat (tenant_id,case_id,seat),
    UNIQUE KEY independent_reviewer (tenant_id,case_id,reviewer_id),
    KEY reviewer_inbox (tenant_id,reviewer_id,id),
    FOREIGN KEY (tenant_id,case_id) REFERENCES practicum_review_cases(tenant_id,id),
    FOREIGN KEY (tenant_id,reviewer_id) REFERENCES accounts(tenant_id,id)
) ENGINE=InnoDB;
