-- Migrace: rychlé reakce (formát aplikace MeshCore One: „@[autor]👍\nhash“ v kanálu, „👍\nhash“ v DM)
ALTER TABLE messages
    ADD COLUMN IF NOT EXISTS sender_ts INT UNSIGNED NULL COMMENT 'Čas odesílatele (unix s) – z meshcore-serveru' AFTER msg_ts,
    ADD COLUMN IF NOT EXISTS react_hash CHAR(8) NULL COMMENT 'Otisk zprávy pro reakce (SHA-256 text + čas odesílatele)' AFTER sender_ts,
    ADD COLUMN IF NOT EXISTS reaction_emoji VARCHAR(32) NULL COMMENT 'Zpráva je reakce: emoji' AFTER react_hash,
    ADD COLUMN IF NOT EXISTS reaction_target VARCHAR(100) NULL COMMENT 'Reakce: autor původní zprávy' AFTER reaction_emoji,
    ADD COLUMN IF NOT EXISTS reaction_hash CHAR(8) NULL COMMENT 'Reakce: otisk původní zprávy' AFTER reaction_target,
    ADD COLUMN IF NOT EXISTS reaction_of BIGINT UNSIGNED NULL COMMENT 'Reakce: ID původní zprávy (když je známá)' AFTER reaction_hash,
    ADD INDEX IF NOT EXISTS ix_messages_react (conv_key, react_hash),
    ADD INDEX IF NOT EXISTS ix_messages_reaction (conv_key, reaction_hash),
    ADD INDEX IF NOT EXISTS ix_messages_reaction_of (reaction_of);
