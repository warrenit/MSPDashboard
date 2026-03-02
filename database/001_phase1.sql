CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(150) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS api_cache (
    cache_key VARCHAR(120) PRIMARY KEY,
    cache_value JSON NOT NULL,
    fetched_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_expires_at (expires_at)
);

-- Default admin user: change password immediately after first login.
-- Password here is: ChangeMe123!
INSERT INTO admin_users (username, password_hash)
VALUES ('admin', '$2y$12$ngmzlTDdTRHA4dE4E02kvepNEqjYreAAIDVKGU6La9zITJa8REwWS')
ON DUPLICATE KEY UPDATE username = username;

INSERT INTO settings (setting_key, setting_value) VALUES
('cache_interval', '60'),
('frontend_refresh_interval', '10')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
