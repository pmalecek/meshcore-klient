-- Migrace: doba uchování zpráv pro jednotlivé konverzace

ALTER TABLE conv_settings
    ADD COLUMN IF NOT EXISTS retention_days SMALLINT UNSIGNED NULL COMMENT 'NULL = výchozí, 0 = navždy, jinak počet dní' AFTER favorite;
