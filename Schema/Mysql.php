<?php
namespace Kanboard\Plugin\AiConnector\Schema;

const VERSION = 4;

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

// One row per AI attempt, so a repeated attempt key is refused: the unique index is the guard.
// attempt_key is VARBINARY, so 'AttemptA' and 'attempta' differ. finished_at stays 0 until the
// attempt ends.
function version_3($pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS aiconnector_attempts (
        id INT NOT NULL AUTO_INCREMENT,
        user_id INT NOT NULL,
        feature VARCHAR(64) NOT NULL DEFAULT \'\',
        attempt_key VARBINARY(64) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT \'running\',
        lease_until BIGINT NOT NULL DEFAULT 0,
        created_at BIGINT NOT NULL DEFAULT 0,
        finished_at BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY(id),
        UNIQUE INDEX aiconnector_attempts_key_uq (user_id, feature, attempt_key),
        INDEX aiconnector_attempts_running_idx (user_id, status),
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARSET=utf8mb4');
}

function version_4($pdo)
{
    // reserve() prunes old attempts by created_at on every call.
    $pdo->exec('CREATE INDEX aiconnector_attempts_created_idx ON aiconnector_attempts(created_at)');
}
