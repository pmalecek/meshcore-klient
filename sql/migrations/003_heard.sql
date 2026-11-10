-- Migrace: „heard“ – kolik observerů analyzeru zachytilo odeslanou zprávu

ALTER TABLE messages
    ADD COLUMN IF NOT EXISTS heard_state     ENUM('pending','heard','none','unsupported','error') NULL AFTER dup_count,
    ADD COLUMN IF NOT EXISTS heard_hash      CHAR(16)          NULL AFTER heard_state,
    ADD COLUMN IF NOT EXISTS heard_count     SMALLINT UNSIGNED NULL COMMENT 'Počet observerů kromě vlastního' AFTER heard_hash,
    ADD COLUMN IF NOT EXISTS heard_total     SMALLINT UNSIGNED NULL COMMENT 'Počet observerů celkem' AFTER heard_count,
    ADD COLUMN IF NOT EXISTS heard_repeats   INT UNSIGNED      NULL AFTER heard_total,
    ADD COLUMN IF NOT EXISTS heard_observers JSON              NULL AFTER heard_repeats,
    ADD COLUMN IF NOT EXISTS heard_attempts  TINYINT UNSIGNED  NOT NULL DEFAULT 0 AFTER heard_observers,
    ADD COLUMN IF NOT EXISTS heard_checked_at DATETIME         NULL AFTER heard_attempts,
    ADD KEY IF NOT EXISTS ix_messages_heard (direction, message_type, heard_attempts, msg_ts);
