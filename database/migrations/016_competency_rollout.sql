CREATE TABLE IF NOT EXISTS competency_rollouts (
    tenant_id BIGINT UNSIGNED PRIMARY KEY,
    mode ENUM('backfill','enabled','drained') NOT NULL,
    high_water BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_enrollment BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS competency_prerequisites (
    tenant_id BIGINT UNSIGNED NOT NULL,
    enrollment_id BIGINT UNSIGNED NOT NULL,
    learner_id BIGINT UNSIGNED NOT NULL,
    cohort_id BIGINT UNSIGNED NOT NULL,
    course_revision_id BIGINT UNSIGNED NOT NULL,
    source_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    imported_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant_id,enrollment_id),
    KEY prior_learning (tenant_id,learner_id,enrollment_id),
    FOREIGN KEY (tenant_id,enrollment_id) REFERENCES enrollments(tenant_id,id),
    FOREIGN KEY (tenant_id,learner_id) REFERENCES accounts(tenant_id,id),
    FOREIGN KEY (tenant_id,cohort_id) REFERENCES cohorts(tenant_id,id)
) ENGINE=InnoDB;

INSERT INTO competency_rollouts (tenant_id,mode,high_water)
SELECT t.id,'backfill',COALESCE((SELECT MAX(e.id) FROM enrollments e WHERE e.tenant_id=t.id),0) FROM tenants t
ON DUPLICATE KEY UPDATE high_water=competency_rollouts.high_water;
