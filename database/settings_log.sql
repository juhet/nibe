CREATE TABLE IF NOT EXISTS nibe_setting_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    changed_at DATETIME NOT NULL,
    setting VARCHAR(191) NOT NULL,
    old_value TEXT NULL,
    new_value TEXT NOT NULL,
    note TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY ix_nibe_setting_changes_changed (changed_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
