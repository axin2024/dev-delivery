CREATE TABLE IF NOT EXISTS test_report_retries (
    tenant_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    report_id BIGINT UNSIGNED NOT NULL,
    source_report_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant_id, project_id, plan_id, report_id),
    FOREIGN KEY (tenant_id, project_id, plan_id, report_id)
        REFERENCES test_reports (tenant_id, project_id, plan_id, id),
    FOREIGN KEY (tenant_id, project_id, plan_id, source_report_id)
        REFERENCES test_reports (tenant_id, project_id, plan_id, id),
    CHECK (report_id <> source_report_id)
) ENGINE=InnoDB;
