-- Migrace: historie Bluetooth klienta (/ble) pro přihlášené uživatele

CREATE TABLE IF NOT EXISTS ble_devices (
    device      CHAR(12)     NOT NULL PRIMARY KEY COMMENT 'Prefix veřejného klíče companionu',
    name        VARCHAR(64)  NULL,
    last_seen   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS ble_messages (
    device      CHAR(12)        NOT NULL,
    id          BIGINT UNSIGNED NOT NULL COMMENT 'ID přidělené prohlížečem (čas v µs)',
    data        MEDIUMTEXT      NULL COMMENT 'Zpráva ve formátu Bluetooth klienta (JSON)',
    msg_ts      DATETIME(3)     NULL,
    deleted     TINYINT(1)      NOT NULL DEFAULT 0,
    updated_at  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (device, id),
    KEY idx_ble_messages_upd (device, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS ble_kv (
    device      CHAR(12)     NOT NULL,
    k           VARCHAR(32)  NOT NULL,
    v           MEDIUMTEXT   NULL,
    updated_at  DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (device, k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
