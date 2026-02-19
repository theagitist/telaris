-- Telaris database schema (MySQL 8+).
-- Used by admin/setup.php. Keep in sync with inc/db.php (project_info, constellations, user_constellations).

-- Table for users
CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(255) PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    firstname VARCHAR(100) NOT NULL,
    lastname VARCHAR(100) NOT NULL,
    type INT NOT NULL DEFAULT 0,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_last_login TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY unique_email (email),
    INDEX idx_type (type),
    INDEX idx_date_created (date_created),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for constellations (id 0 = default, created by setup, cannot be erased)
CREATE TABLE IF NOT EXISTS constellations (
    id INT NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL DEFAULT '',
    tagline VARCHAR(500) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for nodes (using MySQL 8 JSON features)
CREATE TABLE IF NOT EXISTS nodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    constellation_id INT NOT NULL DEFAULT 0,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    url VARCHAR(500) NULL,
    created_by VARCHAR(255) NULL,
    animation JSON NOT NULL DEFAULT (JSON_OBJECT('radius', 5.0, 'theta', 0, 'phi', 0, 'speed', 0.0025, 'phase', 0)),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (constellation_id) REFERENCES constellations(id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_constellation_id (constellation_id),
    INDEX idx_created_by (created_by),
    FULLTEXT INDEX idx_name_desc (name, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for keywords
CREATE TABLE IF NOT EXISTS keywords (
    id INT AUTO_INCREMENT PRIMARY KEY,
    constellation_id INT NOT NULL DEFAULT 0,
    keyword VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_keyword_constellation (keyword, constellation_id),
    FOREIGN KEY (constellation_id) REFERENCES constellations(id),
    INDEX idx_keyword (keyword),
    INDEX idx_constellation_id (constellation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Junction table for node-keyword relationships (many-to-many)
CREATE TABLE IF NOT EXISTS node_keywords (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT NOT NULL,
    keyword_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    FOREIGN KEY (keyword_id) REFERENCES keywords(id) ON DELETE CASCADE,
    UNIQUE KEY unique_node_keyword (node_id, keyword_id),
    INDEX idx_node_id (node_id),
    INDEX idx_keyword_id (keyword_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for project information (one row per locale: en, es, pt).
CREATE TABLE IF NOT EXISTS project_info (
    locale VARCHAR(10) NOT NULL PRIMARY KEY,
    name VARCHAR(2000) NOT NULL DEFAULT '',
    description VARCHAR(2000) NOT NULL DEFAULT '',
    iframe_back_text VARCHAR(2000) NOT NULL DEFAULT '',
    alert_message VARCHAR(2000) NOT NULL DEFAULT '',
    edit_button_text VARCHAR(200) NOT NULL DEFAULT 'Edit',
    loading_text VARCHAR(200) NOT NULL DEFAULT 'Loading'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for editor constellation access (user_id = users.id, constellation_id = constellations.id). Admins see all; editors see only rows here.
CREATE TABLE IF NOT EXISTS user_constellations (
    user_id VARCHAR(255) NOT NULL,
    constellation_id INT NOT NULL,
    PRIMARY KEY (user_id, constellation_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (constellation_id) REFERENCES constellations(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_constellation_id (constellation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for API keys
CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_key VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL DEFAULT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY unique_api_key (api_key),
    INDEX idx_api_key (api_key),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
