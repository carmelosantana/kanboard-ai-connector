<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

/**
 * The aiconnector_attempts table: one row per AI attempt, so a repeated attempt key is refused.
 */
class AttemptsSchemaTest extends Base
{
    private int $ana;
    private int $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__."/../Schema/$driver.php";
        $pdo = $this->container['db']->getConnection();
        \Kanboard\Plugin\AiConnector\Schema\version_1($pdo);
        \Kanboard\Plugin\AiConnector\Schema\version_2($pdo);
        \Kanboard\Plugin\AiConnector\Schema\version_3($pdo);
        $this->ana = (int) $this->container['userModel']->create(['username' => 'ana']);
        $this->bob = (int) $this->container['userModel']->create(['username' => 'bob']);
    }

    private function insert(int $user, string $feature, string $key): bool
    {
        return $this->container['db']->table('aiconnector_attempts')->insert([
            'user_id' => $user, 'feature' => $feature, 'attempt_key' => $key,
        ]);
    }

    private function rows(): int
    {
        return (int) $this->container['db']->table('aiconnector_attempts')->count();
    }

    public function testUniqueKeyPerUserAndFeature(): void
    {
        $this->assertTrue($this->insert($this->ana, 'summary', 'abc'));
        $this->assertTrue($this->insert($this->bob, 'summary', 'abc'), 'another user');
        $this->assertTrue($this->insert($this->ana, 'other', 'abc'), 'another feature');
        $this->assertFalse($this->insert($this->ana, 'summary', 'abc'), 'same user, feature and key');
        $this->assertSame(3, $this->rows());
    }

    public function testKeysAreCaseSensitive(): void
    {
        $this->assertTrue($this->insert($this->ana, 'summary', 'AttemptA'));
        $this->assertTrue($this->insert($this->ana, 'summary', 'attempta'));
        $this->assertSame(2, $this->rows());
    }

    public function testNullFreeColumnsHaveDefaults(): void
    {
        $this->assertTrue($this->insert($this->ana, 'summary', 'abc'));
        $row = $this->container['db']->table('aiconnector_attempts')->findOne();
        $this->assertSame('running', $row['status']);
        $this->assertSame(0, (int) $row['lease_until']);
        $this->assertSame(0, (int) $row['created_at']);
        $this->assertSame(0, (int) $row['finished_at']);
    }

    public function testUserDeleteCascades(): void
    {
        $this->insert($this->ana, 'summary', 'abc');
        $this->insert($this->bob, 'summary', 'abc');
        if (DB_DRIVER === 'sqlite') {
            $this->container['db']->getConnection()->exec('PRAGMA foreign_keys = ON');
        }
        $this->assertTrue($this->container['userModel']->remove($this->ana));
        $this->assertSame(1, $this->rows());
    }
}
