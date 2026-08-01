-- SYSTEM_UPDATES_SCHEMA
CREATE TABLE IF NOT EXISTS system_updates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version VARCHAR(80) NOT NULL,
    title VARCHAR(255) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'queued',
    action_type VARCHAR(30) NOT NULL DEFAULT 'install',
    installer_url VARCHAR(1000) NULL,
    sha256 CHAR(64) NULL,
    manifest_json LONGTEXT NULL,
    backup_path VARCHAR(1000) NULL,
    log_text LONGTEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_system_updates_status (status, created_at),
    KEY idx_system_updates_version (version),
    CONSTRAINT fk_system_updates_user
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
