CREATE TABLE artifact_contents (
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    artifact_id BIGINT UNSIGNED NOT NULL,
    byte_length INT UNSIGNED NOT NULL,
    content MEDIUMBLOB NOT NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    uploaded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant_id, project_id, artifact_id),
    CHECK (byte_length BETWEEN 1 AND 8388608 AND OCTET_LENGTH(content) = byte_length),
    FOREIGN KEY (tenant_id, project_id, artifact_id) REFERENCES build_artifacts (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, uploaded_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB;

CREATE TABLE release_candidates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision INT UNSIGNED NOT NULL,
    previous_id BIGINT UNSIGNED NULL,
    title VARCHAR(160) NOT NULL,
    baseline_id BIGINT UNSIGNED NOT NULL,
    artifact_id BIGINT UNSIGNED NOT NULL,
    test_plan_id BIGINT UNSIGNED NOT NULL,
    manifest MEDIUMBLOB NOT NULL,
    manifest_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    gate_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    state ENUM('draft','frozen','testing','pending_approval','approved','superseded','deployed','accepted') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY candidate_in_project (tenant_id, project_id, id),
    UNIQUE KEY candidate_revision (tenant_id, project_id, code, revision),
    UNIQUE KEY candidate_successor (tenant_id, project_id, previous_id),
    KEY candidate_state (tenant_id, project_id, state, id),
    FOREIGN KEY (tenant_id, project_id, baseline_id) REFERENCES baselines (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, artifact_id) REFERENCES build_artifacts (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, test_plan_id) REFERENCES test_plans (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, previous_id) REFERENCES release_candidates (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, created_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE candidate_scope (
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    candidate_id BIGINT UNSIGNED NOT NULL,
    criterion_id BIGINT UNSIGNED NOT NULL,
    revision_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant_id, project_id, candidate_id, criterion_id),
    KEY scope_revision_candidates (tenant_id, project_id, criterion_id, revision_id, candidate_id),
    FOREIGN KEY (tenant_id, project_id, candidate_id) REFERENCES release_candidates (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, criterion_id, revision_id) REFERENCES criterion_revisions (tenant_id, project_id, criterion_id, id)
) ENGINE=InnoDB;

CREATE TABLE candidate_approvals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    candidate_id BIGINT UNSIGNED NOT NULL,
    gate_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_ids JSON NOT NULL,
    approved_by BIGINT UNSIGNED NOT NULL,
    note TEXT NOT NULL,
    approved_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    revoked_at DATETIME(6) NULL,
    KEY candidate_approval_history (tenant_id, project_id, candidate_id, id),
    FOREIGN KEY (tenant_id, project_id, candidate_id) REFERENCES release_candidates (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, approved_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
