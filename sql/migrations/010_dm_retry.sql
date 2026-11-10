-- Migrace: opakované odesílání přímých zpráv (3× známou cestou, pak 3× floodem)

ALTER TABLE messages
    ADD COLUMN IF NOT EXISTS dm_attempts TINYINT UNSIGNED NULL COMMENT 'Počet odeslaných pokusů DM' AFTER ack_received,
    ADD COLUMN IF NOT EXISTS dm_flood TINYINT(1) NULL COMMENT '1 = poslední pokus šel floodem' AFTER dm_attempts;

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
