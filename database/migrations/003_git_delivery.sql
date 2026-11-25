ALTER TABLE work_items
    ADD COLUMN claimed_by BIGINT UNSIGNED NULL,
    ADD COLUMN claimed_at DATETIME(6) NULL,
    ADD FOREIGN KEY (tenant_id, claimed_by) REFERENCES accounts (tenant_id, id);

CREATE TABLE repositories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    provider ENUM('github','gitee','gitlab','manual') NOT NULL,
    provider_repository_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    connector_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY repository_in_project (tenant_id, project_id, id),
    UNIQUE KEY repository_remote_identity (tenant_id, project_id, provider, provider_repository_id),
    UNIQUE KEY repository_connector (connector_id),
    FOREIGN KEY (tenant_id, project_id) REFERENCES projects (tenant_id, id),
    FOREIGN KEY (tenant_id, created_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE git_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    repository_id BIGINT UNSIGNED NOT NULL,
    branch VARCHAR(240) COLLATE utf8mb4_bin NOT NULL,
    generation BIGINT UNSIGNED NOT NULL,
    commit_sha VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    previous_sha VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    previous_snapshot_id BIGINT UNSIGNED NULL,
    forced BOOLEAN NOT NULL,
    message VARCHAR(500) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY snapshot_for_repository (tenant_id, project_id, repository_id, id),
    UNIQUE KEY branch_generation (tenant_id, project_id, repository_id, branch, generation),
    KEY repository_commit (tenant_id, project_id, repository_id, commit_sha, id),
    FOREIGN KEY (tenant_id, project_id, repository_id) REFERENCES repositories (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, repository_id, previous_snapshot_id)
        REFERENCES git_snapshots (tenant_id, project_id, repository_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE work_git_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    work_item_id BIGINT UNSIGNED NOT NULL,
    repository_id BIGINT UNSIGNED NOT NULL,
    snapshot_id BIGINT UNSIGNED NOT NULL,
    linked_by BIGINT UNSIGNED NOT NULL,
    linked_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY work_snapshot (tenant_id, project_id, work_item_id, repository_id, snapshot_id),
    KEY work_link_page (tenant_id, project_id, work_item_id, id),
    FOREIGN KEY (tenant_id, project_id, work_item_id) REFERENCES work_items (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, repository_id, snapshot_id) REFERENCES git_snapshots (tenant_id, project_id, repository_id, id),
    FOREIGN KEY (tenant_id, linked_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE build_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    repository_id BIGINT UNSIGNED NOT NULL,
    snapshot_id BIGINT UNSIGNED NOT NULL,
    provider_run_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    run_sequence BIGINT UNSIGNED NOT NULL,
    status ENUM('queued','running','passed','failed','cancelled') NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY build_for_project (tenant_id, project_id, id),
    UNIQUE KEY build_remote_run (tenant_id, project_id, repository_id, provider_run_id),
    UNIQUE KEY build_by_snapshot (tenant_id, project_id, repository_id, snapshot_id, run_sequence),
    FOREIGN KEY (tenant_id, project_id, repository_id, snapshot_id) REFERENCES git_snapshots (tenant_id, project_id, repository_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE merge_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    repository_id BIGINT UNSIGNED NOT NULL,
    snapshot_id BIGINT UNSIGNED NOT NULL,
    remote_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_version BIGINT UNSIGNED NOT NULL,
    state ENUM('open','merged','closed') NOT NULL,
    title VARCHAR(160) NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY merge_remote_identity (tenant_id, project_id, repository_id, remote_id),
    KEY merge_by_snapshot (tenant_id, project_id, repository_id, snapshot_id, id),
    FOREIGN KEY (tenant_id, project_id, repository_id, snapshot_id) REFERENCES git_snapshots (tenant_id, project_id, repository_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE git_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    repository_id BIGINT UNSIGNED NOT NULL,
    external_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    type ENUM('ref.updated','build.updated','merge.updated') NOT NULL,
    payload_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    imported_by BIGINT UNSIGNED NULL,
    receipt JSON NOT NULL,
    received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY repository_event_id (tenant_id, project_id, repository_id, external_id),
    KEY repository_event_page (tenant_id, project_id, repository_id, id),
    FOREIGN KEY (tenant_id, project_id, repository_id) REFERENCES repositories (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, imported_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
