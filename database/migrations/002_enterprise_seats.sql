CREATE TABLE IF NOT EXISTS companies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(160) NOT NULL,
    UNIQUE KEY company_scope (tenant_id, id),
    UNIQUE KEY company_code (tenant_id, code),
    FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS company_admins (
    tenant_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant_id, company_id, account_id),
    KEY company_admin_account (tenant_id, account_id, company_id),
    FOREIGN KEY (tenant_id, company_id) REFERENCES companies (tenant_id, id),
    FOREIGN KEY (tenant_id, account_id) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS company_departments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(160) NOT NULL,
    UNIQUE KEY department_scope (tenant_id, company_id, id),
    UNIQUE KEY department_code (tenant_id, company_id, code),
    FOREIGN KEY (tenant_id, company_id) REFERENCES companies (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS company_learners (
    tenant_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    learner_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant_id, company_id, learner_id),
    UNIQUE KEY department_learners (tenant_id, company_id, department_id, learner_id),
    FOREIGN KEY (tenant_id, company_id, department_id) REFERENCES company_departments (tenant_id, company_id, id),
    FOREIGN KEY (tenant_id, learner_id) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS seat_pools (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    contract_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    total INT UNSIGNED NOT NULL,
    next_seat_number BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY seat_pool_scope (tenant_id, id),
    UNIQUE KEY company_seat_pool (tenant_id, company_id, id),
    UNIQUE KEY contract_course (tenant_id, company_id, contract_code, course_id),
    FOREIGN KEY (tenant_id, company_id) REFERENCES companies (tenant_id, id),
    FOREIGN KEY (tenant_id, course_id) REFERENCES courses (tenant_id, id),
    CHECK (starts_on <= ends_on AND total > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS seat_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    pool_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    learner_id BIGINT UNSIGNED NOT NULL,
    cohort_id BIGINT UNSIGNED NOT NULL,
    seat_number BIGINT UNSIGNED NOT NULL,
    state ENUM('assigned','activated','reclaimed') NOT NULL DEFAULT 'assigned',
    enrollment_id BIGINT UNSIGNED NULL,
    active_learner_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN state <> 'reclaimed' THEN learner_id END) STORED,
    assigned_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    activated_at TIMESTAMP(6) NULL,
    reclaimed_at TIMESTAMP(6) NULL,
    UNIQUE KEY assignment_scope (tenant_id, id),
    UNIQUE KEY pool_seat_number (tenant_id, pool_id, seat_number),
    UNIQUE KEY active_pool_learner (tenant_id, pool_id, active_learner_id),
    UNIQUE KEY assignment_enrollment (tenant_id, enrollment_id),
    KEY pool_assignment_state (tenant_id, pool_id, state, id),
    KEY pool_department_state (tenant_id, pool_id, department_id, state),
    KEY learner_assignments (tenant_id, learner_id, id),
    FOREIGN KEY (tenant_id, company_id, pool_id) REFERENCES seat_pools (tenant_id, company_id, id),
    FOREIGN KEY (tenant_id, company_id, department_id, learner_id)
        REFERENCES company_learners (tenant_id, company_id, department_id, learner_id),
    FOREIGN KEY (tenant_id, cohort_id) REFERENCES cohorts (tenant_id, id),
    FOREIGN KEY (tenant_id, enrollment_id) REFERENCES enrollments (tenant_id, id),
    CHECK ((state = 'activated') = (enrollment_id IS NOT NULL)),
    CHECK ((state = 'activated') = (activated_at IS NOT NULL)),
    CHECK ((state = 'reclaimed') = (reclaimed_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS seat_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    pool_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    receipt JSON NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY batch_scope (tenant_id, pool_id, id),
    UNIQUE KEY batch_request (tenant_id, pool_id, actor_id, request_key),
    FOREIGN KEY (tenant_id, pool_id) REFERENCES seat_pools (tenant_id, id),
    FOREIGN KEY (tenant_id, actor_id) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
