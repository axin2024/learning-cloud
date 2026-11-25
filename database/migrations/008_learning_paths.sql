CREATE TABLE IF NOT EXISTS learning_paths (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    published_at DATETIME(6) NULL,
    UNIQUE KEY path_scope (tenant_id, id),
    UNIQUE KEY path_code (tenant_id, code),
    FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS path_steps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    path_id BIGINT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY step_scope (tenant_id, id),
    UNIQUE KEY path_order (tenant_id, path_id, position),
    UNIQUE KEY path_course (tenant_id, path_id, course_id),
    FOREIGN KEY (tenant_id, path_id) REFERENCES learning_paths (tenant_id, id),
    FOREIGN KEY (tenant_id, course_id) REFERENCES courses (tenant_id, id),
    CHECK (position BETWEEN 1 AND 30)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS cohort_path_steps (
    tenant_id BIGINT UNSIGNED NOT NULL,
    cohort_id BIGINT UNSIGNED NOT NULL,
    step_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant_id, cohort_id),
    KEY bound_cohorts (tenant_id, step_id, cohort_id),
    FOREIGN KEY (tenant_id, cohort_id) REFERENCES cohorts (tenant_id, id),
    FOREIGN KEY (tenant_id, step_id) REFERENCES path_steps (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
