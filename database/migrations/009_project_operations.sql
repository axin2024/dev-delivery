CREATE TABLE milestones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(160) NOT NULL,
    baseline_id BIGINT UNSIGNED NOT NULL,
    candidate_id BIGINT UNSIGNED NULL,
    environment_id BIGINT UNSIGNED NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    due_on DATE NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY milestone_in_project (tenant_id, project_id, id),
    UNIQUE KEY milestone_code (tenant_id, project_id, code),
    FOREIGN KEY (tenant_id, project_id, baseline_id) REFERENCES baselines (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, candidate_id) REFERENCES release_candidates (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, environment_id) REFERENCES environments (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, owner_id) REFERENCES accounts (tenant_id, id),
    FOREIGN KEY (tenant_id, created_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE ownership_transfers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    milestone_id BIGINT UNSIGNED NULL,
    work_item_id BIGINT UNSIGNED NULL,
    previous_owner_id BIGINT UNSIGNED NOT NULL,
    next_owner_id BIGINT UNSIGNED NOT NULL,
    previous_claimed_by BIGINT UNSIGNED NULL,
    previous_claimed_at DATETIME(6) NULL,
    changed_by BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(2000) NOT NULL,
    changed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY milestone_transfers (tenant_id, project_id, milestone_id, id),
    KEY work_item_transfers (tenant_id, project_id, work_item_id, id),
    CHECK ((milestone_id IS NOT NULL) <> (work_item_id IS NOT NULL)),
    CHECK (previous_owner_id <> next_owner_id),
    FOREIGN KEY (tenant_id, project_id, milestone_id) REFERENCES milestones (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, work_item_id) REFERENCES work_items (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, previous_owner_id) REFERENCES accounts (tenant_id, id),
    FOREIGN KEY (tenant_id, next_owner_id) REFERENCES accounts (tenant_id, id),
    FOREIGN KEY (tenant_id, previous_claimed_by) REFERENCES accounts (tenant_id, id),
    FOREIGN KEY (tenant_id, changed_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE milestone_metric_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    milestone_id BIGINT UNSIGNED NOT NULL,
    window_start DATETIME(6) NOT NULL,
    window_end DATETIME(6) NOT NULL,
    data JSON NOT NULL,
    captured_by BIGINT UNSIGNED NOT NULL,
    KEY milestone_snapshots (tenant_id, project_id, milestone_id, id),
    CHECK (window_start <= window_end),
    FOREIGN KEY (tenant_id, project_id, milestone_id) REFERENCES milestones (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, captured_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB;

CREATE TABLE review_packages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    deployment_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    content MEDIUMBLOB NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY package_in_tenant (tenant_id, id),
    KEY package_in_project (tenant_id, project_id, id),
    CHECK (OCTET_LENGTH(content) BETWEEN 1 AND 8388608),
    FOREIGN KEY (tenant_id, project_id, deployment_id) REFERENCES delivery_reports (tenant_id, project_id, deployment_id),
    FOREIGN KEY (tenant_id, created_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE review_package_grants (
    tenant_id BIGINT UNSIGNED NOT NULL,
    package_id BIGINT UNSIGNED NOT NULL,
    reviewer_id BIGINT UNSIGNED NOT NULL,
    granted_by BIGINT UNSIGNED NOT NULL,
    expires_on DATE NOT NULL,
    granted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    revoked_at DATETIME(6) NULL,
    PRIMARY KEY (tenant_id, package_id, reviewer_id),
    KEY reviewer_packages (tenant_id, reviewer_id, package_id),
    FOREIGN KEY (tenant_id, package_id) REFERENCES review_packages (tenant_id, id),
    FOREIGN KEY (tenant_id, reviewer_id) REFERENCES accounts (tenant_id, id),
    FOREIGN KEY (tenant_id, granted_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB;
