CREATE TABLE IF NOT EXISTS release_trains (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(160) NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    baseline_id BIGINT UNSIGNED NOT NULL,
    staging_id BIGINT UNSIGNED NOT NULL,
    production_id BIGINT UNSIGNED NOT NULL,
    service_selection JSON NOT NULL,
    state ENUM('draft','frozen','scheduled','running','paused','completed','recovered') NOT NULL DEFAULT 'draft',
    train_rev INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY train_scope (tenant_id,project_id,id),
    UNIQUE KEY train_code (tenant_id,project_id,code),
    FOREIGN KEY (tenant_id,project_id,baseline_id) REFERENCES baselines (tenant_id,project_id,id),
    FOREIGN KEY (tenant_id,project_id,staging_id) REFERENCES environments (tenant_id,project_id,id),
    FOREIGN KEY (tenant_id,project_id,production_id) REFERENCES environments (tenant_id,project_id,id),
    FOREIGN KEY (tenant_id,owner_id) REFERENCES accounts (tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS release_train_revisions (
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    train_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    manifest MEDIUMBLOB NOT NULL,
    manifest_sha CHAR(64) CHARACTER SET ascii NOT NULL,
    changed_service BIGINT UNSIGNED NULL,
    reason VARCHAR(600) NOT NULL,
    frozen_by BIGINT UNSIGNED NOT NULL,
    frozen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant_id,project_id,train_id,revision),
    FOREIGN KEY (tenant_id,project_id,train_id) REFERENCES release_trains (tenant_id,project_id,id),
    FOREIGN KEY (tenant_id,frozen_by) REFERENCES accounts (tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS train_environment_access (
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    environment_id BIGINT UNSIGNED NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    environment_rev INT UNSIGNED NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    nodes JSON NOT NULL,
    PRIMARY KEY (tenant_id,project_id,environment_id),
    FOREIGN KEY (tenant_id,project_id,environment_id) REFERENCES environments (tenant_id,project_id,id),
    FOREIGN KEY (tenant_id,owner_id) REFERENCES accounts (tenant_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS train_rehearsals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    train_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    digest CHAR(64) CHARACTER SET ascii NOT NULL,
    staging_rev INT UNSIGNED NOT NULL,
    target VARCHAR(64) CHARACTER SET ascii NOT NULL,
    operation_key CHAR(32) CHARACTER SET ascii NOT NULL,
    state ENUM('pending','passed','failed') NOT NULL DEFAULT 'pending',
    receipt JSON NULL,
    UNIQUE KEY rehearsal_attempt (tenant_id,project_id,train_id,revision,service_id,staging_rev,target),
    FOREIGN KEY (tenant_id,project_id,train_id,revision) REFERENCES release_train_revisions (tenant_id,project_id,train_id,revision)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS train_component_approvals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    train_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    signed_revision INT UNSIGNED NOT NULL,
    pin_revision INT UNSIGNED NOT NULL,
    digest CHAR(64) CHARACTER SET ascii NOT NULL,
    staging_rev INT UNSIGNED NOT NULL,
    production_rev INT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED NOT NULL,
    approved_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    KEY train_approval (tenant_id,project_id,train_id,service_id,pin_revision,staging_rev,production_rev,expires_at),
    FOREIGN KEY (tenant_id,project_id,train_id,signed_revision) REFERENCES release_train_revisions (tenant_id,project_id,train_id,revision),
    FOREIGN KEY (tenant_id,approved_by) REFERENCES accounts (tenant_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS train_windows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    environment_id BIGINT UNSIGNED NOT NULL,
    train_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    starts_at DATETIME(6) NOT NULL,
    ends_at DATETIME(6) NOT NULL,
    booked_by BIGINT UNSIGNED NOT NULL,
    approvals JSON NOT NULL,
    released_at DATETIME(6) NULL,
    CHECK (ends_at>starts_at),
    KEY calendar (tenant_id,project_id,environment_id,released_at,starts_at,ends_at),
    FOREIGN KEY (tenant_id,project_id,environment_id) REFERENCES environments (tenant_id,project_id,id),
    FOREIGN KEY (tenant_id,project_id,train_id,revision) REFERENCES release_train_revisions (tenant_id,project_id,train_id,revision)
) ENGINE=InnoDB;
