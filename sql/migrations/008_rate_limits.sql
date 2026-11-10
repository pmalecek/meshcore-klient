-- Migrace: omezení počtu požadavků na veřejné endpointy (kontrola přes analyzer z /ble bez přihlášení)

CREATE TABLE IF NOT EXISTS rate_limits (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket      VARCHAR(80)  NOT NULL COMMENT 'např. ip:1.2.3.4 nebo dev:a1b2c3d4e5f6',
    created_at  DATETIME     NOT NULL,
    KEY idx_rate_limits_bucket (bucket, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
