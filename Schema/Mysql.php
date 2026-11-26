<?php
namespace Kanboard\Plugin\AiConnector\Schema;

const VERSION = 1;

// One row per AI request let through by AiQuota, kept for an hour (the quota window).
function version_1($pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS aiconnector_requests (
        id INT NOT NULL AUTO_INCREMENT,
        user_id INT NOT NULL,
        feature VARCHAR(64) NOT NULL DEFAULT \'\',
        created_at BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY(id),
        INDEX aiconnector_requests_user_idx (user_id, created_at),
        INDEX aiconnector_requests_created_idx (created_at),
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARSET=utf8mb4');
}
