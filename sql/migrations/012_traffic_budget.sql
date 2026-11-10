-- Migrace: odhad kreditu zpráv (limit provozu v integraci MeshCore) a zkrácené opakování DM

CREATE TABLE IF NOT EXISTS traffic_log (
    id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    at    DECIMAL(15,3) NOT NULL COMMENT 'Unix čas',
    kind  ENUM('spend','set') NOT NULL COMMENT 'spend = odeslání stálo 1 kredit, set = HA odmítl (kredit znám)',
    val   DOUBLE        NULL,
    KEY idx_traffic_log_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

ALTER TABLE messages
    ADD COLUMN IF NOT EXISTS dm_trimmed TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Část opakování vynechána kvůli limitu provozu';
