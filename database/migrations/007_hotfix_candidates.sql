ALTER TABLE release_candidates
    ADD COLUMN track ENUM('release','hotfix') NOT NULL DEFAULT 'release',
    ADD COLUMN production_environment_id BIGINT UNSIGNED NULL,
    ADD COLUMN production_base_id BIGINT UNSIGNED NULL,
    ADD CONSTRAINT candidate_production_base_type CHECK (
        (track='release' AND production_environment_id IS NULL AND production_base_id IS NULL)
        OR (track='hotfix' AND production_environment_id IS NOT NULL AND production_base_id IS NOT NULL)
    ),
    ADD FOREIGN KEY (tenant_id, project_id, production_environment_id, production_base_id)
        REFERENCES deployments (tenant_id, project_id, environment_id, id);
