CREATE TABLE nibe_connections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source VARCHAR(64) NOT NULL,
    authorization_status VARCHAR(32) NOT NULL DEFAULT 'not_configured',
    last_success_at DATETIME(6) NULL,
    last_error VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE nibe_devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    connection_id BIGINT UNSIGNED NOT NULL,
    source_device_id VARCHAR(191) NOT NULL,
    display_name VARCHAR(191) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_nibe_devices_connection_source (connection_id, source_device_id),
    CONSTRAINT fk_nibe_devices_connection
        FOREIGN KEY (connection_id) REFERENCES nibe_connections (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE nibe_measurement_points (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id BIGINT UNSIGNED NOT NULL,
    source_point_id VARCHAR(191) NOT NULL,
    name VARCHAR(191) NOT NULL,
    unit VARCHAR(64) NULL,
    value_type VARCHAR(32) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_nibe_points_device_source (device_id, source_point_id),
    CONSTRAINT fk_nibe_points_device
        FOREIGN KEY (device_id) REFERENCES nibe_devices (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE nibe_observations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    measurement_point_id BIGINT UNSIGNED NOT NULL,
    source_timestamp DATETIME(6) NULL,
    collected_at DATETIME(6) NOT NULL,
    raw_value LONGTEXT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_nibe_observations_source_time (measurement_point_id, source_timestamp),
    KEY ix_nibe_observations_collected (measurement_point_id, collected_at),
    KEY ix_nibe_observations_source (measurement_point_id, source_timestamp),
    CONSTRAINT fk_nibe_observations_point
        FOREIGN KEY (measurement_point_id) REFERENCES nibe_measurement_points (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE nibe_collection_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    started_at DATETIME(6) NOT NULL,
    ended_at DATETIME(6) NULL,
    result VARCHAR(32) NOT NULL,
    error_message VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY ix_nibe_collection_runs_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE nibe_setting_changes (
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
