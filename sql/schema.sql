-- MeshCore klient – schéma databáze (MariaDB 11.x)
-- Všechny časy se ukládají v UTC.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(64)  NOT NULL,
    totp_secret     VARCHAR(64)  NOT NULL,
    last_totp_step  BIGINT       NULL COMMENT 'Poslední použitý TOTP krok – brání opakovanému použití kódu',
    password_hash   VARCHAR(255) NULL COMMENT 'NULL = jen jednorázový kód',
    password_changed_at DATETIME NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at   DATETIME     NULL,
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip          VARCHAR(45)  NOT NULL,
    username    VARCHAR(64)  NULL,
    success     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_login_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS contacts (
    pubkey_prefix   CHAR(12)      NOT NULL PRIMARY KEY,
    public_key      CHAR(64)      NULL,
    name            VARCHAR(100)  NULL,
    type            TINYINT       NULL COMMENT '1=Client, 2=Repeater, 3=Room Server, 4=Sensor',
    latitude        DECIMAL(9,6)  NULL,
    longitude       DECIMAL(9,6)  NULL,
    out_path_len    SMALLINT      NULL,
    last_advert     DATETIME      NULL,
    raw             JSON          NULL,
    scopes          JSON          NULL COMMENT 'Regiony uzlu podle analyzeru',
    scopes_at       DATETIME      NULL,
    updated_at      DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    KEY ix_contacts_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS channels (
    channel_idx  TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    name         VARCHAR(64)      NULL,
    updated_at   DATETIME(6)      NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS messages (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    srv_id          BIGINT UNSIGNED NULL COMMENT 'ID zprávy v meshcore-serveru',
    entry_id        VARCHAR(64)   NULL,
    entity_id       VARCHAR(128)  NULL,
    conv_key        VARCHAR(20)   NOT NULL COMMENT 'd:<pubkey_prefix> nebo c:<channel_idx>',
    direction       ENUM('in','out') NOT NULL,
    message_type    ENUM('direct','channel') NOT NULL,
    pubkey_prefix   CHAR(12)      NULL,
    channel_idx     TINYINT UNSIGNED NULL,
    sender_name     VARCHAR(100)  NULL,
    author_pk       VARCHAR(16)  NULL COMMENT 'Autor příspěvku v room serveru (prefix klíče)',
    body            TEXT          NOT NULL,
    hop_count       SMALLINT      NULL,
    snr             DECIMAL(5,2)  NULL,
    rssi            SMALLINT      NULL,
    scope           VARCHAR(64)   NULL COMMENT 'Region (flood scope) paketu',
    send_id         CHAR(8)       NULL,
    status          ENUM('received','sent','delivered','failed','queued') NOT NULL DEFAULT 'received',
    repeater_count  SMALLINT      NOT NULL DEFAULT 0,
    ack_received    TINYINT(1)    NULL,
    dm_attempts     TINYINT UNSIGNED NULL COMMENT 'Počet odeslaných pokusů DM',
    dm_flood        TINYINT(1)   NULL COMMENT '1 = poslední pokus šel floodem',
    dm_trimmed      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Část opakování vynechána kvůli limitu provozu',
    error           VARCHAR(255)  NULL,
    dup_of          BIGINT UNSIGNED NULL COMMENT 'ID původní zprávy, pokud jde o duplicitu',
    dup_count       SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Počet přijatých duplicit (u původní zprávy)',
    heard_state     ENUM('pending','heard','none','unsupported','error') NULL,
    heard_hash      CHAR(16)      NULL,
    heard_count     SMALLINT UNSIGNED NULL COMMENT 'Počet observerů kromě vlastního',
    heard_total     SMALLINT UNSIGNED NULL COMMENT 'Počet observerů celkem',
    heard_repeats   INT UNSIGNED  NULL,
    heard_observers JSON          NULL,
    heard_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    heard_checked_at DATETIME     NULL,
    rx_log          JSON          NULL,
    msg_ts          DATETIME(3)   NOT NULL COMMENT 'Čas zprávy z události (UTC)',
    created_at      DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at      DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_messages_send_id (send_id),
    UNIQUE KEY uq_messages_srv_id (srv_id),
    KEY ix_messages_conv (conv_key, id),
    KEY ix_messages_updated (updated_at),
    KEY ix_messages_match (entity_id, msg_ts),
    KEY ix_messages_dup (dup_of),
    KEY ix_messages_heard (direction, message_type, heard_attempts, msg_ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS conv_reads (
    conv_key      VARCHAR(20)     NOT NULL PRIMARY KEY,
    last_read_id  BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS worker_state (
    k           VARCHAR(64)  NOT NULL PRIMARY KEY,
    v           MEDIUMTEXT   NULL,
    updated_at  DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS conv_settings (
    conv_key    VARCHAR(20)  NOT NULL PRIMARY KEY,
    color       VARCHAR(7)   NULL COMMENT 'Barva kroužku (#rrggbb)',
    background  JSON         NULL COMMENT 'Pozadí chatu, NULL = výchozí',
    favorite    TINYINT(1)   NOT NULL DEFAULT 0,
    retention_days SMALLINT UNSIGNED NULL COMMENT 'NULL = výchozí, 0 = navždy, jinak počet dní',
    scope       VARCHAR(32)  NULL COMMENT 'Region pro odesílání: NULL = výchozí companionu, * = bez regionu, jinak název (bez #)',
    updated_at  DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS app_settings (
    k           VARCHAR(64)  NOT NULL PRIMARY KEY,
    v           JSON         NULL,
    updated_at  DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Bluetooth klient (/ble) – historie přihlášených uživatelů
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

-- Omezení počtu požadavků (veřejné endpointy)
CREATE TABLE IF NOT EXISTS rate_limits (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket      VARCHAR(80)  NOT NULL COMMENT 'např. ip:1.2.3.4 nebo dev:a1b2c3d4e5f6',
    created_at  DATETIME     NOT NULL,
    KEY idx_rate_limits_bucket (bucket, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Fronta opakovaného odesílání přímých zpráv
CREATE TABLE IF NOT EXISTS dm_queue (
    msg_id      BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    pk          CHAR(12)     NOT NULL,
    body        TEXT         NOT NULL,
    ts          INT UNSIGNED NOT NULL COMMENT 'Časová značka zprávy (stejná pro všechny pokusy)',
    attempt     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Počet již odeslaných pokusů',
    direct_left TINYINT UNSIGNED NOT NULL DEFAULT 3,
    flood_left  TINYINT UNSIGNED NOT NULL DEFAULT 3,
    flood       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = fáze floodu',
    need_reset  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Před dalším pokusem resetovat cestu',
    acks        VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Očekávané ACK kódy (hex, čárkou)',
    state       ENUM('pending','inflight','wait','done','failed') NOT NULL DEFAULT 'pending',
    next_at     DATETIME     NOT NULL,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_dm_queue_state (state, next_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Zprávy do kanálů čekající na volný provoz
CREATE TABLE IF NOT EXISTS send_queue (
    msg_id      BIGINT UNSIGNED NOT NULL PRIMARY KEY COMMENT 'Zástupný řádek zprávy (status queued)',
    channel_idx TINYINT UNSIGNED NOT NULL,
    body        TEXT         NOT NULL,
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    state       ENUM('pending','inflight') NOT NULL DEFAULT 'pending',
    next_at     DATETIME     NOT NULL,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_send_queue_due (state, next_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS traffic_log (
    id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    at    DECIMAL(15,3) NOT NULL COMMENT 'Unix čas',
    kind  ENUM('spend','set') NOT NULL COMMENT 'spend = odeslání stálo 1 kredit, set = HA odmítl (kredit znám)',
    val   DOUBLE        NULL,
    KEY idx_traffic_log_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
