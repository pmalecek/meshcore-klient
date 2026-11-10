-- Migrace: autor zprávy z room serveru (prvních 8 hex znaků jeho klíče)

ALTER TABLE messages
    ADD COLUMN IF NOT EXISTS author_pk VARCHAR(16) NULL COMMENT 'Autor příspěvku v room serveru (prefix klíče)' AFTER sender_name;
