CREATE TABLE business_signoff_decisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    deployment_id BIGINT UNSIGNED NOT NULL,
    criterion_id BIGINT UNSIGNED NOT NULL,
    revision_id BIGINT UNSIGNED NOT NULL,
    decision ENUM('accepted','blocked','waived') NOT NULL,
    note VARCHAR(2000) NOT NULL,
    signer_id BIGINT UNSIGNED NOT NULL,
    signer_name VARCHAR(160) NOT NULL,
    responsible_id BIGINT UNSIGNED NULL,
    responsible_name VARCHAR(160) NULL,
    retest_due_on DATE NULL,
    decided_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY deployment_criterion_history (tenant_id, project_id, deployment_id, criterion_id, id),
    KEY signoff_criterion_revision (tenant_id, project_id, criterion_id, revision_id, deployment_id, id),
    CHECK ((decision='waived' AND responsible_id IS NOT NULL AND responsible_name IS NOT NULL AND retest_due_on IS NOT NULL)
        OR (decision<>'waived' AND responsible_id IS NULL AND responsible_name IS NULL AND retest_due_on IS NULL)),
    FOREIGN KEY (tenant_id, project_id, deployment_id) REFERENCES deployments (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, project_id, criterion_id, revision_id) REFERENCES criterion_revisions (tenant_id, project_id, criterion_id, id),
    FOREIGN KEY (tenant_id, signer_id) REFERENCES accounts (tenant_id, id),
    FOREIGN KEY (tenant_id, responsible_id) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE delivery_reports (
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    deployment_id BIGINT UNSIGNED NOT NULL,
    digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    content MEDIUMBLOB NOT NULL,
    archived_by BIGINT UNSIGNED NOT NULL,
    archived_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant_id, project_id, deployment_id),
    CHECK (OCTET_LENGTH(content) BETWEEN 1 AND 8388608),
    FOREIGN KEY (tenant_id, project_id, deployment_id) REFERENCES deployments (tenant_id, project_id, id),
    FOREIGN KEY (tenant_id, archived_by) REFERENCES accounts (tenant_id, id)
) ENGINE=InnoDB;
