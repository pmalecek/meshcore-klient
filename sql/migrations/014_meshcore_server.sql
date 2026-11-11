-- Migrace: napojení na meshcore-server (backend = 'server')
ALTER TABLE messages
    ADD COLUMN IF NOT EXISTS srv_id BIGINT UNSIGNED NULL COMMENT 'ID zprávy v meshcore-serveru' AFTER id,
    ADD UNIQUE INDEX IF NOT EXISTS uq_messages_srv_id (srv_id);
