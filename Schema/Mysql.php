<?php
namespace Kanboard\Plugin\AiConnector\Schema;

const VERSION = 2;

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

// One row per user, updated first in AiQuota's transaction: a row lock that makes a user's
// check-and-count atomic, so parallel requests cannot all pass the hourly quota.
function version_2($pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS aiconnector_quota_users (
        user_id INT NOT NULL,
        locked_at BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY(user_id),
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARSET=utf8mb4');
}
