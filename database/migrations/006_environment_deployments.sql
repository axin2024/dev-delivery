CREATE TABLE deployments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    environment_id BIGINT UNSIGNED NOT NULL,
    candidate_id BIGINT UNSIGNED NOT NULL,
    artifact_id BIGINT UNSIGNED NOT NULL,
    request_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind ENUM('promote','rollback') NOT NULL,
    promotion_source_id BIGINT UNSIGNED NULL,
    previous_head_id BIGINT UNSIGNED NULL,
    restored_from_id BIGINT UNSIGNED NULL,
    approved_gate_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status ENUM('queued','running','unknown','succeeded','failed','cancelled') NOT NULL DEFAULT 'queued',
    active_slot TINYINT GENERATED ALWAYS AS (IF(status IN ('queued','running','unknown'), 1, NULL)) STORED,
    external_run_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL,
    external_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
    requested_by BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    completed_at DATETIME(6) NULL,
    UNIQUE KEY deployment_in_project (tenant_id, project_id, id),
    UNIQUE KEY deployment_in_environment (tenant_id, project_id, environment_id, id),
    UNIQUE KEY environment_request_key (tenant_id, project_id, environment_id, request_key),
    UNIQUE KEY environment_active_deployment (tenant_id, project_id, environment_id, active_slot),
    UNIQUE KEY environment_external_run (tenant_id, project_id, environment_id, external_run_id),
    KEY deployments_for_candidate (tenant_id, project_id, candidate_id, id),
    CHECK ((kind='promote' AND restored_from_id IS NULL) OR (kind='rollback' AND previous_head_id IS NOT NULL AND restored_from_id IS NOT NULL AND promotion_source_id IS NULL)),
    FOREIGN KEY (tenant_id, project_id, environment_id) REFERENCES environments (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, candidate_id) REFERENCES release_candidates (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, artifact_id) REFERENCES build_artifacts (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, promotion_source_id) REFERENCES deployments (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, environment_id, previous_head_id) REFERENCES deployments (tenant_id, project_id, environment_id, id),
    FOREIGN KEY (tenant_id, project_id, environment_id, restored_from_id) REFERENCES deployments (tenant_id, project_id, environment_id, id),
    FOREIGN KEY (tenant_id, requested_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE environment_heads (
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    environment_id BIGINT UNSIGNED NOT NULL,
    deployment_id BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant_id, project_id, environment_id),
    FOREIGN KEY (tenant_id, project_id, environment_id, deployment_id) REFERENCES deployments (tenant_id, project_id, environment_id, id)
) ENGINE=InnoDB;

CREATE TABLE deployment_receipts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    deployment_id BIGINT UNSIGNED NOT NULL,
    event_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_sequence BIGINT UNSIGNED NOT NULL,
    reported_status ENUM('running','succeeded','failed','cancelled') NOT NULL,
    external_run_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    imported_by BIGINT UNSIGNED NULL,
    response JSON NOT NULL,
    received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY deployment_event_identity (tenant_id, project_id, deployment_id, event_id),
    UNIQUE KEY deployment_event_sequence (tenant_id, project_id, deployment_id, external_sequence),
    KEY deployment_receipt_page (tenant_id, project_id, deployment_id, id),
    FOREIGN KEY (tenant_id, project_id, deployment_id) REFERENCES deployments (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, imported_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE deployment_connectors (
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    environment_id BIGINT UNSIGNED NOT NULL,
    connector_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant_id, project_id, environment_id),
    UNIQUE KEY deployment_connector_identity (connector_id),
    FOREIGN KEY (tenant_id, project_id, environment_id) REFERENCES environments (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, created_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB;

ALTER TABLE audit_events MODIFY actor_id BIGINT UNSIGNED NULL;
