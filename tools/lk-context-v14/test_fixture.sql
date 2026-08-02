CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    email VARCHAR(190) NOT NULL,
    role VARCHAR(30) NOT NULL,
    account_status VARCHAR(30) NOT NULL DEFAULT 'active',
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE clients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    manager_user_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE client_users (
    client_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (client_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE projects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    site_url VARCHAR(1000) NULL,
    goal_ids_json JSON NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE project_client_links (
    project_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (project_id),
    KEY idx_project_client_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE site_monitors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    url VARCHAR(1000) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO users (id, name, email, role) VALUES
    (1, 'Admin', 'admin@example.test', 'administrator'),
    (2, 'Manager A', 'manager-a@example.test', 'manager'),
    (3, 'Client A', 'client-a@example.test', 'client'),
    (4, 'Manager B', 'manager-b@example.test', 'manager');

INSERT INTO clients (id, name, manager_user_id) VALUES
    (10, 'Компания А', 2),
    (20, 'Компания Б', 4);

INSERT INTO client_users (client_id, user_id) VALUES (10, 3);

INSERT INTO projects (id, name, site_url, goal_ids_json, active) VALUES
    (101, 'Проект А1', 'https://a-main.example', JSON_ARRAY(1001, 1002), 1),
    (102, 'Проект А2', 'https://a-second.example', JSON_ARRAY(2001), 1),
    (201, 'Проект Б1', 'https://b-main.example', JSON_ARRAY(3001), 1),
    (301, 'Проект без клиента', 'https://orphan.example', JSON_ARRAY(), 1);

INSERT INTO project_client_links (project_id, client_id) VALUES
    (101, 10),
    (102, 10),
    (201, 20);

INSERT INTO site_monitors (project_id, url) VALUES
    (101, 'https://a-sub.example'),
    (201, 'https://b-monitor.example');
