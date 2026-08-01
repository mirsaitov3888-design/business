-- SITE_MONITORING_MODULE_SCHEMA
CREATE TABLE IF NOT EXISTS monitored_sites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    base_url VARCHAR(1000) NOT NULL,
    host VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    check_interval_minutes INT UNSIGNED NOT NULL DEFAULT 5,
    slow_threshold_ms INT UNSIGNED NOT NULL DEFAULT 3000,
    notify_email TINYINT(1) NOT NULL DEFAULT 0,
    notify_telegram TINYINT(1) NOT NULL DEFAULT 0,
    technical_email VARCHAR(255) NULL,
    marketing_email VARCHAR(255) NULL,
    technical_telegram_chat VARCHAR(100) NULL,
    marketing_telegram_chat VARCHAR(100) NULL,
    expected_metrika_ids TEXT NULL,
    last_status VARCHAR(20) NOT NULL DEFAULT 'unknown',
    last_http_code INT NULL,
    last_response_ms INT NULL,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    last_checked_at DATETIME NULL,
    last_audit_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_monitor_site_url (project_id, base_url(190)),
    KEY idx_monitor_sites_due (is_active, last_checked_at),
    KEY idx_monitor_sites_project (project_id, is_active),
    CONSTRAINT fk_monitored_sites_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_availability_checks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id BIGINT UNSIGNED NOT NULL,
    checked_at DATETIME NOT NULL,
    is_up TINYINT(1) NOT NULL,
    http_code INT NULL,
    response_ms INT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 1,
    final_url VARCHAR(1000) NULL,
    error_text TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_monitor_checks_site_date (site_id, checked_at),
    KEY idx_monitor_checks_cleanup (checked_at),
    CONSTRAINT fk_monitor_checks_site
        FOREIGN KEY (site_id) REFERENCES monitored_sites(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_audits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id BIGINT UNSIGNED NOT NULL,
    run_type VARCHAR(30) NOT NULL DEFAULT 'scheduled',
    http_code INT NULL,
    final_url VARCHAR(1000) NULL,
    title TEXT NULL,
    description TEXT NULL,
    h1 TEXT NULL,
    h1_count INT UNSIGNED NOT NULL DEFAULT 0,
    canonical VARCHAR(1000) NULL,
    meta_robots VARCHAR(500) NULL,
    x_robots_tag VARCHAR(500) NULL,
    indexing_allowed TINYINT(1) NOT NULL DEFAULT 1,
    indexing_reason TEXT NULL,
    robots_status INT NULL,
    robots_hash CHAR(64) NULL,
    robots_summary TEXT NULL,
    sitemap_url VARCHAR(1000) NULL,
    sitemap_status INT NULL,
    sitemap_hash CHAR(64) NULL,
    favicon_url VARCHAR(1000) NULL,
    favicon_status INT NULL,
    metrika_ids_json TEXT NULL,
    webvisor_enabled TINYINT(1) NULL,
    ssl_valid TINYINT(1) NULL,
    ssl_expires_at DATETIME NULL,
    ssl_days_left INT NULL,
    dns_json MEDIUMTEXT NULL,
    dns_hash CHAR(64) NULL,
    domain_name VARCHAR(255) NULL,
    domain_registered_at DATETIME NULL,
    domain_expires_at DATETIME NULL,
    domain_days_left INT NULL,
    domain_status VARCHAR(100) NULL,
    summary_json MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_monitor_audits_site_date (site_id, created_at),
    CONSTRAINT fk_monitor_audits_site
        FOREIGN KEY (site_id) REFERENCES monitored_sites(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_incidents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id BIGINT UNSIGNED NOT NULL,
    incident_type VARCHAR(80) NOT NULL,
    category VARCHAR(30) NOT NULL DEFAULT 'technical',
    severity VARCHAR(20) NOT NULL DEFAULT 'warning',
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    title VARCHAR(255) NOT NULL,
    details TEXT NULL,
    started_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    duration_seconds BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_monitor_incidents_site_status (site_id, status, started_at),
    KEY idx_monitor_incidents_type (site_id, incident_type, status),
    CONSTRAINT fk_monitor_incidents_site
        FOREIGN KEY (site_id) REFERENCES monitored_sites(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id BIGINT UNSIGNED NOT NULL,
    event_key VARCHAR(120) NOT NULL,
    category VARCHAR(30) NOT NULL DEFAULT 'technical',
    severity VARCHAR(20) NOT NULL DEFAULT 'info',
    old_value MEDIUMTEXT NULL,
    new_value MEDIUMTEXT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    notified_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_monitor_events_site_date (site_id, created_at),
    KEY idx_monitor_events_notify (notified_at, created_at),
    CONSTRAINT fk_monitor_events_site
        FOREIGN KEY (site_id) REFERENCES monitored_sites(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_notification_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    channel VARCHAR(30) NOT NULL,
    recipient VARCHAR(255) NULL,
    status VARCHAR(30) NOT NULL,
    error_text TEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_monitor_notification_event (event_id, created_at),
    CONSTRAINT fk_monitor_notification_event
        FOREIGN KEY (event_id) REFERENCES monitor_events(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_worker_state (
    state_key VARCHAR(100) NOT NULL,
    state_value MEDIUMTEXT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (state_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
