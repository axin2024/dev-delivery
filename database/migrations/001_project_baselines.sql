CREATE TABLE tenants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(160) NOT NULL,
    password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    UNIQUE KEY account_in_tenant (tenant_id, id),
    UNIQUE KEY account_email (tenant_id, email),
    FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE account_roles (
    tenant_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (tenant_id, account_id, role),
    FOREIGN KEY (tenant_id, account_id) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB;

CREATE TABLE projects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(160) NOT NULL,
    charter TEXT NOT NULL,
    product_owner_id BIGINT UNSIGNED NOT NULL,
    business_signer_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY project_in_tenant (tenant_id, id),
    UNIQUE KEY project_code (tenant_id, code),
    KEY product_projects (tenant_id, product_owner_id, id),
    KEY signer_projects (tenant_id, business_signer_id, id),
    FOREIGN KEY (tenant_id, product_owner_id) REFERENCES accounts (tenant_id, id),
    FOREIGN KEY (tenant_id, business_signer_id) REFERENCES accounts (tenant_id, id),
    CHECK (product_owner_id <> business_signer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE project_members (
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant_id, project_id, account_id),
    KEY member_projects (tenant_id, account_id, project_id),
    FOREIGN KEY (tenant_id, project_id) REFERENCES projects (tenant_id, id),
    FOREIGN KEY (tenant_id, account_id) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB;

CREATE TABLE requirements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(160) NOT NULL,
    summary TEXT NOT NULL,
    UNIQUE KEY requirement_in_project (tenant_id, project_id, id),
    UNIQUE KEY requirement_code (tenant_id, project_id, code),
    FOREIGN KEY (tenant_id, project_id) REFERENCES projects (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE criteria (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    requirement_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    UNIQUE KEY criterion_in_project (tenant_id, project_id, id),
    UNIQUE KEY criterion_code (tenant_id, project_id, requirement_id, code),
    FOREIGN KEY (tenant_id, project_id, requirement_id) REFERENCES requirements (tenant_id, project_id, id)
) ENGINE=InnoDB;

CREATE TABLE criterion_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    criterion_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    statement TEXT NOT NULL,
    digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY criterion_revision_in_project (tenant_id, project_id, criterion_id, id),
    UNIQUE KEY criterion_version (tenant_id, project_id, criterion_id, version),
    FOREIGN KEY (tenant_id, project_id, criterion_id) REFERENCES criteria (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, author_id) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE baselines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    sequence INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    charter_version INT UNSIGNED NOT NULL,
    charter_snapshot JSON NOT NULL,
    manifest_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    frozen_by BIGINT UNSIGNED NOT NULL,
    frozen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY baseline_in_project (tenant_id, project_id, id),
    UNIQUE KEY baseline_sequence (tenant_id, project_id, sequence),
    FOREIGN KEY (tenant_id, project_id) REFERENCES projects (tenant_id, id),
    FOREIGN KEY (tenant_id, frozen_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE baseline_criteria (
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    baseline_id BIGINT UNSIGNED NOT NULL,
    criterion_id BIGINT UNSIGNED NOT NULL,
    revision_id BIGINT UNSIGNED NOT NULL,
    requirement_snapshot JSON NOT NULL,
    PRIMARY KEY (tenant_id, project_id, baseline_id, criterion_id),
    KEY frozen_criterion (tenant_id, project_id, criterion_id, baseline_id),
    FOREIGN KEY (tenant_id, project_id, baseline_id) REFERENCES baselines (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, criterion_id, revision_id)
        REFERENCES criterion_revisions (tenant_id, project_id, criterion_id, id)
) ENGINE=InnoDB;

CREATE TABLE audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    object_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    object_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    details JSON NOT NULL,
    occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY object_timeline (tenant_id, object_type, object_id, id),
    FOREIGN KEY (tenant_id, actor_id) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB;
