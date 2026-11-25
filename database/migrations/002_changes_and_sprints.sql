CREATE TABLE change_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    baseline_id BIGINT UNSIGNED NOT NULL,
    proposed_by BIGINT UNSIGNED NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME(6) NULL,
    decision_note TEXT NULL,
    impact_snapshot JSON NULL,
    UNIQUE KEY change_in_project (tenant_id, project_id, id),
    UNIQUE KEY change_in_baseline (tenant_id, project_id, id, baseline_id),
    FOREIGN KEY (tenant_id, project_id, baseline_id) REFERENCES baselines (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, proposed_by) REFERENCES accounts (tenant_id, id),
    FOREIGN KEY (tenant_id, reviewed_by) REFERENCES accounts (tenant_id, id),
    CHECK (reviewed_by IS NULL OR reviewed_by <> proposed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE baseline_criteria ADD UNIQUE KEY baseline_revision (tenant_id, project_id, baseline_id, criterion_id, revision_id);

CREATE TABLE change_criteria (
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    change_id BIGINT UNSIGNED NOT NULL,
    baseline_id BIGINT UNSIGNED NOT NULL,
    criterion_id BIGINT UNSIGNED NOT NULL,
    prior_revision_id BIGINT UNSIGNED NOT NULL,
    approved_revision_id BIGINT UNSIGNED NULL,
    title VARCHAR(160) NOT NULL,
    statement TEXT NOT NULL,
    PRIMARY KEY (tenant_id, project_id, change_id, criterion_id),
    FOREIGN KEY (tenant_id, project_id, change_id, baseline_id)
        REFERENCES change_requests (tenant_id, project_id, id, baseline_id),
    FOREIGN KEY (tenant_id, project_id, baseline_id, criterion_id, prior_revision_id)
        REFERENCES baseline_criteria (tenant_id, project_id, baseline_id, criterion_id, revision_id),
    FOREIGN KEY (tenant_id, project_id, criterion_id, approved_revision_id)
        REFERENCES criterion_revisions (tenant_id, project_id, criterion_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE sprints (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    team VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(160) NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    capacity INT UNSIGNED NOT NULL,
    UNIQUE KEY sprint_in_project (tenant_id, project_id, id),
    UNIQUE KEY sprint_code (tenant_id, project_id, team, code),
    FOREIGN KEY (tenant_id, project_id) REFERENCES projects (tenant_id, id),
    CHECK (starts_on <= ends_on AND capacity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE work_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    criterion_id BIGINT UNSIGNED NOT NULL,
    revision_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(160) NOT NULL,
    team VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    effort INT UNSIGNED NOT NULL,
    sprint_id BIGINT UNSIGNED NULL,
    review_change_id BIGINT UNSIGNED NULL,
    UNIQUE KEY work_item_in_project (tenant_id, project_id, id),
    UNIQUE KEY work_item_code (tenant_id, project_id, code),
    KEY work_sprint (tenant_id, project_id, sprint_id, id, effort),
    KEY work_revision (tenant_id, project_id, criterion_id, revision_id, id),
    FOREIGN KEY (tenant_id, project_id, criterion_id, revision_id)
        REFERENCES criterion_revisions (tenant_id, project_id, criterion_id, id),
    FOREIGN KEY (tenant_id, project_id, sprint_id) REFERENCES sprints (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, review_change_id) REFERENCES change_requests (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, owner_id) REFERENCES accounts (tenant_id, id),
    CHECK (effort > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE test_cases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    criterion_id BIGINT UNSIGNED NOT NULL,
    revision_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(160) NOT NULL,
    steps TEXT NOT NULL,
    expected_result TEXT NOT NULL,
    review_change_id BIGINT UNSIGNED NULL,
    UNIQUE KEY test_case_in_project (tenant_id, project_id, id),
    UNIQUE KEY test_case_code (tenant_id, project_id, code),
    KEY test_case_revision (tenant_id, project_id, criterion_id, revision_id, id),
    FOREIGN KEY (tenant_id, project_id, criterion_id, revision_id)
        REFERENCES criterion_revisions (tenant_id, project_id, criterion_id, id),
    FOREIGN KEY (tenant_id, project_id, review_change_id) REFERENCES change_requests (tenant_id, project_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE work_dependencies (
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    work_item_id BIGINT UNSIGNED NOT NULL,
    predecessor_id BIGINT UNSIGNED NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    due_on DATE NOT NULL,
    PRIMARY KEY (tenant_id, project_id, work_item_id, predecessor_id),
    KEY dependency_predecessor (tenant_id, project_id, predecessor_id, work_item_id),
    FOREIGN KEY (tenant_id, project_id, work_item_id) REFERENCES work_items (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, predecessor_id) REFERENCES work_items (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, owner_id) REFERENCES accounts (tenant_id, id),
    CHECK (work_item_id <> predecessor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
