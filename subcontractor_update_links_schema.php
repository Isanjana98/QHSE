<?php
function ensureSubcontractorUpdateLinksTable(mysqli $conn): bool
{
    return (bool)$conn->query("
        CREATE TABLE IF NOT EXISTS subcontractor_update_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            subcontractor_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_subcontractor_update_token (token_hash),
            KEY idx_subcontractor_update_active (subcontractor_id, consumed_at, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}