CREATE TABLE IF NOT EXISTS train_pilots (
    tenant_id BIGINT UNSIGNED PRIMARY KEY,
    state ENUM('preparing','enabled','draining') NOT NULL DEFAULT 'preparing',
    through_id BIGINT UNSIGNED NOT NULL,
    cursor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS train_legacy_baselines (
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    baseline_id BIGINT UNSIGNED NOT NULL,
    manifest_digest CHAR(64) CHARACTER SET ascii NOT NULL,
    PRIMARY KEY (tenant_id,baseline_id),
    FOREIGN KEY (tenant_id,project_id,baseline_id) REFERENCES baselines(tenant_id,project_id,id)
) ENGINE=InnoDB;
