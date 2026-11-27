<?php
namespace Kanboard\Plugin\AiConnector\Schema;

const VERSION = 4;

// One row per AI request let through by AiQuota, kept for an hour (the quota window).
function version_1($pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS aiconnector_requests (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        feature VARCHAR(64) NOT NULL DEFAULT \'\',
        created_at INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS aiconnector_requests_user_idx ON aiconnector_requests(user_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS aiconnector_requests_created_idx ON aiconnector_requests(created_at)');
}

// One row per user, updated first in AiQuota's transaction: a write lock that makes a user's
// check-and-count atomic, so parallel requests cannot all pass the hourly quota.
function version_2($pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS aiconnector_quota_users (
        user_id INTEGER PRIMARY KEY,
        locked_at INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )');
}

// One row per AI attempt, so a repeated attempt key is refused: the unique index is the guard.
// attempt_key is TEXT with the default binary collation, so 'AttemptA' and 'attempta' differ.
// finished_at stays 0 until the attempt ends.
function version_3($pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS aiconnector_attempts (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        feature VARCHAR(64) NOT NULL DEFAULT \'\',
        attempt_key TEXT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT \'running\',
        lease_until INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL DEFAULT 0,
        finished_at INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS aiconnector_attempts_key_uq ON aiconnector_attempts(user_id, feature, attempt_key)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS aiconnector_attempts_running_idx ON aiconnector_attempts(user_id, status)');
}

function version_4($pdo)
{
    // reserve() prunes old attempts by created_at on every call.
    $pdo->exec('CREATE INDEX IF NOT EXISTS aiconnector_attempts_created_idx ON aiconnector_attempts(created_at)');
}
