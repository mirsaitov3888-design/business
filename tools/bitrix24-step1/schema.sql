-- BITRIX24_STEP1_SCHEMA
CREATE TABLE IF NOT EXISTS bitrix24_project_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    bitrix_group_id BIGINT UNSIGNED NOT NULL,
    bitrix_group_name VARCHAR(255) NOT NULL,
    bitrix_company_id BIGINT UNSIGNED NULL,
    bitrix_company_name VARCHAR(255) NULL,
    report_tag VARCHAR(100) NOT NULL DEFAULT 'client_report',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_bitrix_project_link (project_id),
    KEY idx_bitrix_group (bitrix_group_id),
    KEY idx_bitrix_company (bitrix_company_id),
    CONSTRAINT fk_bitrix_project_link
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bitrix24_task_cache (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    bitrix_task_id BIGINT UNSIGNED NOT NULL,
    bitrix_group_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(1000) NOT NULL,
    status VARCHAR(100) NOT NULL,
    responsible_id BIGINT UNSIGNED NULL,
    responsible_name VARCHAR(255) NULL,
    tags_json TEXT NOT NULL,
    created_date DATETIME NULL,
    changed_date DATETIME NULL,
    closed_date DATETIME NULL,
    time_spent_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
    raw_json LONGTEXT NULL,
    synced_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_bitrix_task (project_id, bitrix_task_id),
    KEY idx_bitrix_task_project (project_id),
    KEY idx_bitrix_task_group (bitrix_group_id),
    CONSTRAINT fk_bitrix_task_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bitrix24_elapsed_cache (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    bitrix_task_id BIGINT UNSIGNED NOT NULL,
    bitrix_elapsed_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    user_name VARCHAR(255) NULL,
    seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
    comment_text TEXT NULL,
    created_date DATETIME NULL,
    date_start DATETIME NULL,
    date_stop DATETIME NULL,
    raw_json LONGTEXT NULL,
    synced_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_bitrix_elapsed (project_id, bitrix_elapsed_id),
    KEY idx_bitrix_elapsed_task (project_id, bitrix_task_id),
    KEY idx_bitrix_elapsed_date (project_id, created_date),
    CONSTRAINT fk_bitrix_elapsed_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bitrix24_sync_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    tasks_count INT UNSIGNED NOT NULL DEFAULT 0,
    elapsed_count INT UNSIGNED NOT NULL DEFAULT 0,
    elapsed_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
    warnings_json TEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_bitrix_sync_project (project_id, created_at),
    CONSTRAINT fk_bitrix_sync_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
