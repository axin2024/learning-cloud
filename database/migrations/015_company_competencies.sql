CREATE TABLE IF NOT EXISTS competency_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    cohort_id BIGINT UNSIGNED NOT NULL,
    disclosure_minimum INT UNSIGNED NOT NULL,
    UNIQUE KEY plan_target (tenant_id,company_id,department_id,cohort_id),
    UNIQUE KEY plan_scope (tenant_id,company_id,id),
    FOREIGN KEY (tenant_id,company_id,department_id) REFERENCES company_departments(tenant_id,company_id,id),
    FOREIGN KEY (tenant_id,cohort_id) REFERENCES cohort_rubrics(tenant_id,cohort_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS competency_plan_members (
    tenant_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    learner_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant_id,company_id,plan_id,learner_id),
    FOREIGN KEY (tenant_id,company_id,plan_id) REFERENCES competency_plans(tenant_id,company_id,id),
    FOREIGN KEY (tenant_id,company_id,learner_id) REFERENCES company_learners(tenant_id,company_id,learner_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS competency_employment (
    tenant_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    learner_id BIGINT UNSIGNED NOT NULL,
    employed BOOLEAN NOT NULL,
    PRIMARY KEY (tenant_id,company_id,learner_id),
    FOREIGN KEY (tenant_id,company_id,learner_id) REFERENCES company_learners(tenant_id,company_id,learner_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS competency_training_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    learner_id BIGINT UNSIGNED NOT NULL,
    cohort_id BIGINT UNSIGNED NOT NULL,
    initial_score DECIMAL(5,1) NULL,
    initial_case_id BIGINT UNSIGNED NULL,
    initial_missing JSON NOT NULL,
    instructions VARCHAR(1000) NOT NULL,
    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    completed_at DATETIME(6) NULL,
    credential_id BIGINT UNSIGNED NULL,
    open_learner BIGINT UNSIGNED GENERATED ALWAYS AS (IF(completed_at IS NULL,learner_id,NULL)) STORED,
    UNIQUE KEY one_open_training (tenant_id,company_id,plan_id,open_learner),
    KEY learner_training (tenant_id,learner_id,id),
    FOREIGN KEY (tenant_id,company_id,plan_id) REFERENCES competency_plans(tenant_id,company_id,id),
    FOREIGN KEY (tenant_id,company_id,learner_id) REFERENCES company_learners(tenant_id,company_id,learner_id),
    FOREIGN KEY (tenant_id,cohort_id) REFERENCES cohort_rubrics(tenant_id,cohort_id),
    FOREIGN KEY (tenant_id,initial_case_id) REFERENCES practicum_review_cases(tenant_id,id),
    FOREIGN KEY (credential_id) REFERENCES competency_credentials(id)
) ENGINE=InnoDB;
