<?php
namespace Kanboard\Plugin\AiConnector\Schema;

const VERSION = 1;

// One row per AI request let through by AiQuota, kept for an hour (the quota window).
function version_1($pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS aiconnector_requests (
        id SERIAL PRIMARY KEY,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        feature VARCHAR(64) NOT NULL DEFAULT \'\',
        created_at BIGINT NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS aiconnector_requests_user_idx ON aiconnector_requests(user_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS aiconnector_requests_created_idx ON aiconnector_requests(created_at)');
}
