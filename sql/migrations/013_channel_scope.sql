-- Migrace: region (flood scope) pro odesílání do konkrétních kanálů
ALTER TABLE conv_settings
    ADD COLUMN IF NOT EXISTS scope VARCHAR(32) NULL COMMENT 'Region pro odesílání: NULL = výchozí companionu, * = bez regionu, jinak název (bez #)' AFTER retention_days;
