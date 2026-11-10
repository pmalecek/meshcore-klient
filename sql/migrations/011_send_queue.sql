-- Migrace: čekání na volný provoz (limit provozu v integraci MeshCore) a automatické odeslání

ALTER TABLE messages
    MODIFY status ENUM('received','sent','delivered','failed','queued') NOT NULL DEFAULT 'received';

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
