<?php
function ensureWorkerRegistrationSchema(mysqli $conn): bool
{
    $linksCreated = $conn->query("
        CREATE TABLE IF NOT EXISTS worker_registration_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            subcontractor_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_worker_registration_token (token_hash),
            KEY idx_worker_registration_active (subcontractor_id, used_at, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $resultsCreated = $conn->query("
        CREATE TABLE IF NOT EXISTS worker_training_results (
            worker_id INT NOT NULL PRIMARY KEY,
            subcontractor_id INT NOT NULL,
            invitation_id BIGINT UNSIGNED NULL,
            answers LONGTEXT NOT NULL,
            videos_watched LONGTEXT NOT NULL,
            score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            total_questions SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            acknowledged_at DATETIME NOT NULL,
            submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_worker_training_subcontractor (subcontractor_id),
            KEY idx_worker_training_invitation (invitation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    return (bool)$linksCreated && (bool)$resultsCreated;
}