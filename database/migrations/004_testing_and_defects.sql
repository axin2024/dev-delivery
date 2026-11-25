CREATE TABLE test_case_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    case_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    criterion_id BIGINT UNSIGNED NOT NULL,
    criterion_revision_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    steps TEXT NOT NULL,
    expected_result TEXT NOT NULL,
    junit_class VARCHAR(240) NOT NULL,
    junit_name VARCHAR(240) NOT NULL,
    authored_by BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY case_version_in_project (tenant_id, project_id, id),
    UNIQUE KEY case_version_number (tenant_id, project_id, case_id, version),
    FOREIGN KEY (tenant_id, project_id, case_id) REFERENCES test_cases (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, criterion_id, criterion_revision_id)
        REFERENCES criterion_revisions (tenant_id, project_id, criterion_id, id),
    FOREIGN KEY (tenant_id, authored_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO test_case_versions (tenant_id, project_id, case_id, version, criterion_id, criterion_revision_id, title, steps,
    expected_result, junit_class, junit_name, authored_by, created_at)
SELECT c.tenant_id, c.project_id, c.id, 1, c.criterion_id, c.revision_id, c.title, c.steps, c.expected_result, '', c.code,
       (SELECT e.actor_id FROM audit_events e WHERE e.tenant_id=c.tenant_id AND e.object_type='test_case'
        AND e.object_id=c.id AND e.action='test_case_created' ORDER BY e.id LIMIT 1),
       COALESCE((SELECT e.occurred_at FROM audit_events e WHERE e.tenant_id=c.tenant_id AND e.object_type='test_case'
        AND e.object_id=c.id AND e.action='test_case_created' ORDER BY e.id LIMIT 1), UTC_TIMESTAMP(6))
FROM test_cases c;

CREATE TABLE build_artifacts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    build_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    registered_by BIGINT UNSIGNED NOT NULL,
    registered_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY artifact_in_project (tenant_id, project_id, id),
    UNIQUE KEY build_artifact_identity (tenant_id, project_id, build_id, sha256),
    FOREIGN KEY (tenant_id, project_id, build_id) REFERENCES build_runs (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, registered_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE environments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(160) NOT NULL,
    stage ENUM('development', 'staging', 'production') NOT NULL,
    UNIQUE KEY environment_in_project (tenant_id, project_id, id),
    UNIQUE KEY environment_code (tenant_id, project_id, code),
    FOREIGN KEY (tenant_id, project_id) REFERENCES projects (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE test_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    baseline_id BIGINT UNSIGNED NOT NULL,
    artifact_id BIGINT UNSIGNED NOT NULL,
    environment_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(160) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY plan_in_project (tenant_id, project_id, id),
    UNIQUE KEY plan_code (tenant_id, project_id, code),
    FOREIGN KEY (tenant_id, project_id, baseline_id) REFERENCES baselines (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, artifact_id) REFERENCES build_artifacts (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, environment_id) REFERENCES environments (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, created_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE test_plan_cases (
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    case_version_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant_id, project_id, plan_id, case_version_id),
    FOREIGN KEY (tenant_id, project_id, plan_id) REFERENCES test_plans (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, case_version_id) REFERENCES test_case_versions (tenant_id, project_id, id)
) ENGINE=InnoDB;

CREATE TABLE test_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    source_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempt BIGINT UNSIGNED NOT NULL,
    format ENUM('junit', 'manual') NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_xml MEDIUMBLOB NULL,
    matched_count INT UNSIGNED NOT NULL,
    other_count INT UNSIGNED NOT NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY report_in_plan (tenant_id, project_id, plan_id, id),
    UNIQUE KEY report_in_project (tenant_id, project_id, id),
    UNIQUE KEY report_source_key (tenant_id, project_id, plan_id, source_key),
    UNIQUE KEY report_attempt (tenant_id, project_id, plan_id, attempt),
    FOREIGN KEY (tenant_id, project_id, plan_id) REFERENCES test_plans (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, recorded_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE test_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    report_id BIGINT UNSIGNED NOT NULL,
    case_version_id BIGINT UNSIGNED NOT NULL,
    outcome ENUM('passed', 'failed', 'skipped') NOT NULL,
    duration_ms BIGINT UNSIGNED NULL,
    summary VARCHAR(800) NOT NULL,
    UNIQUE KEY result_in_project (tenant_id, project_id, id),
    UNIQUE KEY result_in_report (tenant_id, project_id, plan_id, report_id, case_version_id),
    KEY result_by_plan_case (tenant_id, project_id, plan_id, case_version_id, report_id),
    FOREIGN KEY (tenant_id, project_id, plan_id, report_id)
        REFERENCES test_reports (tenant_id, project_id, plan_id, id),
    FOREIGN KEY (tenant_id, project_id, plan_id, case_version_id)
        REFERENCES test_plan_cases (tenant_id, project_id, plan_id, case_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE defects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    failed_result_id BIGINT UNSIGNED NOT NULL,
    fix_artifact_id BIGINT UNSIGNED NULL,
    retest_result_id BIGINT UNSIGNED NULL,
    severity ENUM('critical', 'major', 'minor') NOT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    state ENUM('open', 'fixed', 'closed') NOT NULL DEFAULT 'open',
    opened_by BIGINT UNSIGNED NOT NULL,
    fixed_by BIGINT UNSIGNED NULL,
    closed_by BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY defect_in_project (tenant_id, project_id, id),
    UNIQUE KEY defect_for_failure (tenant_id, project_id, failed_result_id),
    FOREIGN KEY (tenant_id, project_id, failed_result_id) REFERENCES test_results (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, retest_result_id) REFERENCES test_results (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, fix_artifact_id) REFERENCES build_artifacts (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, opened_by) REFERENCES accounts (tenant_id, id),
    FOREIGN KEY (tenant_id, fixed_by) REFERENCES accounts (tenant_id, id),
    FOREIGN KEY (tenant_id, closed_by) REFERENCES accounts (tenant_id, id),
    CHECK (closed_by IS NULL OR (fixed_by IS NOT NULL AND closed_by <> fixed_by))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
