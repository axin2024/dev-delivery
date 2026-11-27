CREATE TABLE IF NOT EXISTS train_recoveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    train_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    production_rev INT UNSIGNED NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(600) NOT NULL,
    state ENUM('inspecting','ready','restoring','repair','done') NOT NULL DEFAULT 'inspecting',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY train_revision (train_id,revision),
    FOREIGN KEY (train_id) REFERENCES release_trains(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS train_recovery_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recovery_id BIGINT UNSIGNED NOT NULL,
    target_operation BIGINT UNSIGNED NOT NULL UNIQUE,
    operation_key CHAR(32) CHARACTER SET ascii NOT NULL UNIQUE,
    inspection JSON NULL,
    receipt JSON NULL,
    verified_at DATETIME(6) NULL,
    FOREIGN KEY (recovery_id) REFERENCES train_recoveries(id),
    FOREIGN KEY (target_operation) REFERENCES train_target_operations(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS train_forward_repairs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recovery_id BIGINT UNSIGNED NOT NULL UNIQUE,
    item_id BIGINT UNSIGNED NOT NULL,
    evidence JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY (recovery_id) REFERENCES train_recoveries(id),
    FOREIGN KEY (item_id) REFERENCES train_recovery_items(id)
) ENGINE=InnoDB;
