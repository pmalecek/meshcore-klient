-- Migrace: regiony (scope) zpráv a repeaterů

ALTER TABLE messages
    ADD COLUMN IF NOT EXISTS scope VARCHAR(64) NULL COMMENT 'Region (flood scope) paketu' AFTER rssi;

ALTER TABLE contacts
    ADD COLUMN IF NOT EXISTS scopes    JSON     NULL COMMENT 'Regiony uzlu podle analyzeru (default, configured, transported, declared)' AFTER raw,
    ADD COLUMN IF NOT EXISTS scopes_at DATETIME NULL AFTER scopes;
