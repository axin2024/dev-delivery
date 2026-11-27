CREATE TABLE IF NOT EXISTS train_release_clearances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    window_id BIGINT UNSIGNED NOT NULL UNIQUE,
    production_rev INT UNSIGNED NOT NULL,
    manifest_sha CHAR(64) CHARACTER SET ascii NOT NULL,
    component_approvals JSON NOT NULL,
    FOREIGN KEY (window_id) REFERENCES train_windows (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS train_wave_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    train_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    production_rev INT UNSIGNED NOT NULL,
    min_requests INT UNSIGNED NOT NULL,
    max_error_bps INT UNSIGNED NOT NULL,
    interval_seconds INT UNSIGNED NOT NULL,
    request_digest CHAR(64) CHARACTER SET ascii NOT NULL,
    baseline JSON NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    UNIQUE KEY train_plan (tenant_id,project_id,train_id,revision,production_rev),
    FOREIGN KEY (tenant_id,project_id,train_id,revision) REFERENCES release_train_revisions (tenant_id,project_id,train_id,revision)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS train_waves (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id BIGINT UNSIGNED NOT NULL,
    sequence_no INT UNSIGNED NOT NULL,
    targets JSON NOT NULL,
    state ENUM('waiting','accepted','running','paused','done','failed','recovered') NOT NULL DEFAULT 'waiting',
    completed_at DATETIME(6) NULL,
    UNIQUE KEY planned_wave (plan_id,sequence_no),
    FOREIGN KEY (plan_id) REFERENCES train_wave_plans(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS train_samples (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wave_id BIGINT UNSIGNED NOT NULL,
    sample_key VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    measured_at DATETIME(6) NOT NULL,
    requests INT UNSIGNED NOT NULL,
    errors INT UNSIGNED NOT NULL,
    received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY sample_identity (wave_id,sample_key),
    KEY measured_window (wave_id,measured_at,requests,errors),
    CHECK (errors<=requests),
    FOREIGN KEY (wave_id) REFERENCES train_waves(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS train_observations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wave_id BIGINT UNSIGNED NOT NULL,
    anchor_at DATETIME(6) NOT NULL,
    sample_through DATETIME(6) NOT NULL,
    last_sample BIGINT UNSIGNED NOT NULL,
    before_requests BIGINT UNSIGNED NOT NULL,
    before_errors BIGINT UNSIGNED NOT NULL,
    after_requests BIGINT UNSIGNED NOT NULL,
    after_errors BIGINT UNSIGNED NOT NULL,
    decision ENUM('allow','pause','await_samples') NOT NULL,
    FOREIGN KEY (wave_id) REFERENCES train_waves(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS train_gate_waivers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    observation_id BIGINT UNSIGNED NOT NULL UNIQUE,
    digest CHAR(64) CHARACTER SET ascii NOT NULL,
    reason VARCHAR(600) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    approved_by BIGINT UNSIGNED NOT NULL,
    FOREIGN KEY (observation_id) REFERENCES train_observations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS train_executions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    wave_id BIGINT UNSIGNED NOT NULL UNIQUE,
    actor_id BIGINT UNSIGNED NOT NULL,
    request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_digest CHAR(64) CHARACTER SET ascii NOT NULL,
    observation_id BIGINT UNSIGNED NOT NULL,
    approval_id BIGINT UNSIGNED NOT NULL,
    trace_id CHAR(32) CHARACTER SET ascii NOT NULL,
    accepted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY tenant_request (tenant_id,request_key),
    FOREIGN KEY (wave_id) REFERENCES train_waves(id),
    FOREIGN KEY (observation_id) REFERENCES train_observations(id),
    FOREIGN KEY (approval_id) REFERENCES train_release_clearances(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS train_target_operations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    execution_id BIGINT UNSIGNED NOT NULL,
    node VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    artifact_id BIGINT UNSIGNED NOT NULL,
    digest CHAR(64) CHARACTER SET ascii NOT NULL,
    previous_digest CHAR(64) CHARACTER SET ascii NULL,
    operation_key CHAR(32) CHARACTER SET ascii NOT NULL UNIQUE,
    state ENUM('waiting','working','unknown','done','failed') NOT NULL DEFAULT 'waiting',
    lease_token CHAR(32) CHARACTER SET ascii NULL,
    lease_until DATETIME(6) NULL,
    receipt JSON NULL,
    applied_at DATETIME(6) NULL,
    UNIQUE KEY target_once (execution_id,node,service_id),
    KEY next_target (execution_id,state,id),
    FOREIGN KEY (execution_id) REFERENCES train_executions(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS train_adapter_calls (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operation_id BIGINT UNSIGNED NOT NULL,
    command VARCHAR(32) CHARACTER SET ascii NOT NULL,
    started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ended_at DATETIME(6) NULL,
    outcome VARCHAR(64) CHARACTER SET ascii NULL,
    observed_digest CHAR(64) CHARACTER SET ascii NULL,
    FOREIGN KEY (operation_id) REFERENCES train_target_operations(id)
) ENGINE=InnoDB;
