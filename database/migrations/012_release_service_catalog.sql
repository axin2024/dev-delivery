CREATE TABLE IF NOT EXISTS release_services (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(160) NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    repository_id BIGINT UNSIGNED NOT NULL,
    artifact_id BIGINT UNSIGNED NULL,
    signed_by BIGINT UNSIGNED NULL,
    signed_at DATETIME(6) NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY service_in_project (tenant_id,project_id,id),
    UNIQUE KEY service_code (tenant_id,project_id,code),
    FOREIGN KEY (tenant_id,project_id) REFERENCES projects (tenant_id,id),
    FOREIGN KEY (tenant_id,owner_id) REFERENCES accounts (tenant_id,id),
    FOREIGN KEY (tenant_id,signed_by) REFERENCES accounts (tenant_id,id),
    FOREIGN KEY (tenant_id,project_id,repository_id) REFERENCES repositories (tenant_id,project_id,id),
    FOREIGN KEY (tenant_id,project_id,artifact_id) REFERENCES build_artifacts (tenant_id,project_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS release_service_dependencies (
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    upstream_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant_id,project_id,service_id,upstream_id),
    FOREIGN KEY (tenant_id,project_id,service_id) REFERENCES release_services (tenant_id,project_id,id),
    FOREIGN KEY (tenant_id,project_id,upstream_id) REFERENCES release_services (tenant_id,project_id,id)
) ENGINE=InnoDB;
