-- Run once after backing up the database.
ALTER TABLE events
    ADD COLUMN reminder_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN reminder_value INT DEFAULT NULL,
    ADD COLUMN reminder_unit ENUM('minutes','hours','days','weeks') DEFAULT NULL,
    ADD COLUMN reminder_time TIME DEFAULT NULL,
    ADD COLUMN reminder_method ENUM('email','notification','both') NOT NULL DEFAULT 'both',
    ADD COLUMN reminder_at DATETIME DEFAULT NULL,
    ADD COLUMN reminder_status ENUM('disabled','scheduled','processing','sent','failed') NOT NULL DEFAULT 'disabled',
    ADD COLUMN reminder_sent_at DATETIME DEFAULT NULL,
    ADD COLUMN reminder_last_error TEXT DEFAULT NULL,
    ADD COLUMN reminder_attempts INT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS event_reminder_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    registration_id INT NULL,
    recipient VARCHAR(190) NOT NULL,
    delivery_method ENUM('email','notification','both') NOT NULL,
    status ENUM('sent','failed','skipped') NOT NULL,
    error_message TEXT NULL,
    attempted_at DATETIME NOT NULL,
    INDEX idx_event_attempt (event_id, attempted_at),
    INDEX idx_recipient (recipient),
    CONSTRAINT fk_event_reminder_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
