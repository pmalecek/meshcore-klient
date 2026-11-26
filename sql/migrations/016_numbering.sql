-- Migrace: volitelné číslování odesílaných DM („[12] text“) a hlídání chybějících čísel u protistrany
ALTER TABLE conv_settings
    ADD COLUMN IF NOT EXISTS number_out TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Číslovat odesílané zprávy (jen DM)' AFTER scope,
    ADD COLUMN IF NOT EXISTS number_next INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Číslo další odeslané zprávy' AFTER number_out;
