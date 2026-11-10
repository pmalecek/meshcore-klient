-- Migrace: detekce duplicitních zpráv
-- Spusť jednou na existující databázi, pak: php bin/dedup.php

ALTER TABLE messages
    ADD COLUMN IF NOT EXISTS dup_of    BIGINT UNSIGNED   NULL COMMENT 'ID původní zprávy, pokud jde o duplicitu' AFTER error,
    ADD COLUMN IF NOT EXISTS dup_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Počet přijatých duplicit (u původní zprávy)' AFTER dup_of,
    ADD KEY IF NOT EXISTS ix_messages_dup (dup_of);
