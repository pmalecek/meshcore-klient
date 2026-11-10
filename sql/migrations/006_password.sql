-- Migrace: volitelné přihlašování heslem (vedle jednorázového kódu)

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) NULL COMMENT 'NULL = jen jednorázový kód' AFTER last_totp_step,
    ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL AFTER password_hash;
