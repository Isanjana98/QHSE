<?php
function ensureToolInspectionQrTable(mysqli $conn): bool
{
    return (bool)$conn->query("
        CREATE TABLE IF NOT EXISTS tool_inspection_qr_tokens (
            record_id INT NOT NULL PRIMARY KEY,
            public_token CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_tool_inspection_public_token (public_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}