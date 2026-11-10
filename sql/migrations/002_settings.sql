-- Migrace: vzhled (pozadí, barvy), oblíbené, stav zařízení z HA

CREATE TABLE IF NOT EXISTS conv_settings (
    conv_key    VARCHAR(20)  NOT NULL PRIMARY KEY,
    color       VARCHAR(7)   NULL COMMENT 'Barva kroužku (#rrggbb)',
    background  JSON         NULL COMMENT 'Pozadí chatu, NULL = výchozí',
    favorite    TINYINT(1)   NOT NULL DEFAULT 0,
    updated_at  DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS app_settings (
    k           VARCHAR(64)  NOT NULL PRIMARY KEY,
    v           JSON         NULL,
    updated_at  DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- snapshot stavu zařízení z HA může být větší než 64 kB
ALTER TABLE worker_state MODIFY v MEDIUMTEXT NULL;
