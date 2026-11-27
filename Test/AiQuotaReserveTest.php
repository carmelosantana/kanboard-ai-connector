<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\AiConnector\Model\AiQuota;
use Kanboard\Plugin\AiConnector\Model\Reservation;
use KanboardTests\units\Base;

/**
 * AiQuota::reserve(): one atomic step before a streamed request. It refuses a repeated attempt
 * key, enforces the per-person concurrent limit and the hourly quota, and counts the request.
 */
class AiQuotaReserveTest extends Base
{
    private int $now = 1_800_000_000;
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

    private function quota(): AiQuota
    {
        return new AiQuota($this->container, fn () => $this->now);
    }

    private function setting(string $key, string $value): void
    {
        $this->container['configModel']->save([$key => $value]);
        $this->container['memoryCache']->flush();
    }

    private function reserve(string $key, string $feature = 'summary', int $user = 0, float $maxDuration = 120.0): Reservation
    {
        return $this->quota()->reserve($user ?: $this->ana, $feature, 'hello', $key, $maxDuration);
    }

    private function counted(): int
    {
        return (int) $this->container['db']->table(AiQuota::TABLE)->count();
    }

    /** @return list<array<string, mixed>> */
    private function attempts(): array
    {
        return $this->container['db']->table('aiconnector_attempts')->asc('id')->findAll();
    }

    public function testReserveCountsOneRequestAndOpensARunningAttempt(): void
    {
        $r = $this->reserve('k1', 'summary', 0, 90.2);
        $this->assertSame(Reservation::OK, $r->outcome);
        $this->assertTrue($r->ok());
        $this->assertNull($r->message);
        $this->assertGreaterThan(0, $r->attemptId);

        $this->assertSame(1, $this->quota()->usedThisHour($this->ana));
        $attempts = $this->attempts();
        $this->assertCount(1, $attempts);
        $this->assertSame($r->attemptId, (int) $attempts[0]['id']);
        $this->assertSame($this->ana, (int) $attempts[0]['user_id']);
        $this->assertSame('summary', $attempts[0]['feature']);
        $this->assertSame('k1', $attempts[0]['attempt_key']);
        $this->assertSame('running', $attempts[0]['status']);
        $this->assertSame($this->now, (int) $attempts[0]['created_at']);
        $this->assertSame($this->now + 91 + 60, (int) $attempts[0]['lease_until']);
        $this->assertSame(0, (int) $attempts[0]['finished_at']);
    }

    public function testDuplicateKeyIsRefusedWithoutCharging(): void
    {
        $this->assertTrue($this->reserve('k1')->ok());
        $r = $this->reserve('k1');
        $this->assertSame(Reservation::DUPLICATE_ATTEMPT, $r->outcome);
        $this->assertFalse($r->ok());
        $this->assertSame('This AI request was already sent.', $r->message);
        $this->assertNull($r->attemptId);
        $this->assertSame(1, $this->counted());
        $this->assertCount(1, $this->attempts());
    }

    public function testDuplicateIsReportedBeforeQuotaAtTheLastSlot(): void
    {
        $quota = $this->quota();
        for ($i = 0; $i < 29; $i++) {
            $this->assertNull($quota->consume($this->ana, 'summary', 'x'));
        }
        $this->assertTrue($this->reserve('last')->ok(), 'slot 30');
        $this->assertSame(Reservation::DUPLICATE_ATTEMPT, $this->reserve('last')->outcome);
        $this->assertSame(Reservation::QUOTA, $this->reserve('fresh')->outcome);
        $this->assertSame(30, $this->counted());
    }

    public function testSameKeyOnAnotherFeatureIsNotADuplicate(): void
    {
        $this->assertTrue($this->reserve('k1', 'summary')->ok());
        $this->assertTrue($this->reserve('k1', 'other')->ok());
        $this->assertTrue($this->reserve('k1', 'summary', $this->bob)->ok(), 'another user');
        $this->assertSame(3, $this->counted());
    }

    public function testTooManyRunningAtTheConcurrentLimit(): void
    {
        foreach (['a', 'b', 'c'] as $key) {
            $this->assertTrue($this->reserve($key)->ok());
        }
        $r = $this->reserve('d');
        $this->assertSame(Reservation::TOO_MANY_RUNNING, $r->outcome);
        $this->assertSame('You have too many AI requests running. Wait for one to finish and try again.', $r->message);
        $this->assertSame(3, $this->counted(), 'a refused request is not charged');
        $this->assertCount(3, $this->attempts());
        $this->assertTrue($this->reserve('e', 'summary', $this->bob)->ok(), 'the limit is per person');
    }

    public function testExpiredLeaseNoLongerCountsAndIsMarkedFailed(): void
    {
        foreach (['a', 'b', 'c'] as $key) {
            $this->assertTrue($this->reserve($key, 'summary', 0, 10.0)->ok());
        }
        $this->now += 69; // lease_until = start + 70: still live
        $this->assertSame(Reservation::TOO_MANY_RUNNING, $this->reserve('d', 'summary', 0, 10.0)->outcome);

        $this->now += 1; // at lease_until the lease has ended
        $this->assertTrue($this->reserve('d', 'summary', 0, 10.0)->ok());
        $attempts = $this->attempts();
        foreach (array_slice($attempts, 0, 3) as $row) {
            $this->assertSame('failed', $row['status']);
            $this->assertSame($this->now, (int) $row['finished_at']);
        }
        $this->assertSame('running', $attempts[3]['status']);
        $this->assertSame(Reservation::DUPLICATE_ATTEMPT, $this->reserve('a')->outcome, 'an expired key stays used');
    }

    public function testClosedAttemptFreesASlotButItsKeyStaysUsed(): void
    {
        $first = $this->reserve('a');
        $this->reserve('b');
        $this->reserve('c');
        $this->now += 5;
        $this->quota()->closeAttempt($this->ana, $first->attemptId, 'finished');
        $this->now += 5;
        $this->quota()->closeAttempt($this->ana, $first->attemptId, 'failed');

        $row = $this->attempts()[0];
        $this->assertSame('finished', $row['status'], 'a second close changes nothing');
        $this->assertSame($this->now - 5, (int) $row['finished_at']);

        $this->assertTrue($this->reserve('d')->ok(), 'the closed attempt no longer counts as running');
        $this->assertSame(Reservation::DUPLICATE_ATTEMPT, $this->reserve('a')->outcome);
    }

    public function testCloseAttemptOnlyEndsTheCallersOwnAttempt(): void
    {
        $bobs = $this->reserve('a', 'summary', $this->bob);
        $this->quota()->closeAttempt($this->ana, $bobs->attemptId, 'failed');
        $this->assertSame('running', $this->attempts()[0]['status'], "another person's id closes nothing");
        $this->assertEmpty($this->attempts()[0]['finished_at']);

        $this->quota()->closeAttempt($this->bob, $bobs->attemptId, 'finished');
        $this->assertSame('finished', $this->attempts()[0]['status']);
    }

    public function testCloseAttemptRejectsOtherStatuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->quota()->closeAttempt($this->ana, $this->reserve('a')->attemptId, 'running');
    }

    public function testAttemptRowsOlderThanADayArePruned(): void
    {
        $this->reserve('a');
        $this->reserve('b', 'summary', $this->bob);
        $this->now += 86399;
        $this->reserve('c');
        $this->assertCount(3, $this->attempts());
        $this->now += 1;
        $this->reserve('d');
        $this->assertSame(['c', 'd'], array_column($this->attempts(), 'attempt_key'));
    }

    public function testInputOverTheCapIsTooLongAndUncounted(): void
    {
        $this->setting(AiQuota::MAX_INPUT_CHARS, '10');
        $r = $this->quota()->reserve($this->ana, 'summary', ['12345', '123456'], 'k1', 60.0);
        $this->assertSame(Reservation::TOO_LONG, $r->outcome);
        $this->assertStringContainsString('10', $r->message);
        $this->assertSame(0, $this->counted());
        $this->assertCount(0, $this->attempts());
    }

    public function testUnknownUserIsNotSignedIn(): void
    {
        foreach ([0, 999999] as $user) {
            $r = $this->quota()->reserve($user, 'summary', 'x', 'k1', 60.0);
            $this->assertSame(Reservation::NOT_SIGNED_IN, $r->outcome, (string) $user);
            $this->assertSame('Sign in to use AI features.', $r->message);
        }
        $this->assertSame(0, $this->counted());
    }

    public function testReserveInsideATransactionThrows(): void
    {
        $db = $this->container['db'];
        $db->startTransaction();
        try {
            $this->expectException(\LogicException::class);
            $this->reserve('k1');
        } finally {
            $db->cancelTransaction();
        }
    }

    /** A db whose aiconnector_attempts table is $table (a \PicoDb\Table subclass factory). */
    private function withAttemptsTable(callable $table): void
    {
        $real = $this->container['db'];
        $this->container['db'] = new class($real, $table) {
            public function __construct(private $db, private $table) {}

            public function table($name)
            {
                return $name === 'aiconnector_attempts' ? ($this->table)($this->db, $name) : $this->db->table($name);
            }

            public function __call($method, $args)
            {
                return $this->db->$method(...$args);
            }
        };
    }

    public function testUniqueViolationFromARaceReadsAsDuplicate(): void
    {
        // Another request committed the same key between reserve()'s check and its insert:
        // the check is made to miss it, the insert then hits the unique key.
        $real = $this->container['db'];
        $real->table('aiconnector_attempts')->insert(['user_id' => $this->ana, 'feature' => 'summary', 'attempt_key' => 'k1', 'created_at' => $this->now]);
        $lies = new \ArrayObject(['left' => 1]);
        $this->withAttemptsTable(fn ($db, $name) => new class($db, $name, $lies) extends \PicoDb\Table {
            public function __construct($db, $name, private \ArrayObject $lies)
            {
                parent::__construct($db, $name);
            }

            public function exists()
            {
                // Only reserve()'s first key check misses the row; the re-read after rollback sees it.
                if ($this->lies['left']-- > 0) {
                    return false;
                }
                return parent::exists();
            }
        });
        $r = $this->reserve('k1');
        $this->container['db'] = $real;

        $this->assertSame(Reservation::DUPLICATE_ATTEMPT, $r->outcome);
        $this->assertSame(0, $this->counted(), 'the count row is rolled back with the attempt');
        $this->assertCount(1, $this->attempts(), 'only the other request\'s attempt');
        $this->assertFalse($real->getConnection()->inTransaction());
        $this->assertTrue($this->reserve('k2')->ok(), 'the connection still works');
    }

    public function testAFailedInsertWithNoMatchingKeyIsUnavailableNotDuplicate(): void
    {
        // PicoDb returns false for any integrity failure (a foreign key on Postgres, any 23000
        // on MariaDB): only a key that is really there is a duplicate.
        $real = $this->container['db'];
        $this->withAttemptsTable(fn ($db, $name) => new class($db, $name) extends \PicoDb\Table {
            public function persist(array $data)
            {
                return false;
            }
        });
        $r = $this->reserve('k1');
        $this->container['db'] = $real;

        $this->assertSame(Reservation::UNAVAILABLE, $r->outcome);
        $this->assertSame(0, $this->counted());
        $this->assertCount(0, $this->attempts());
        $this->assertTrue($this->reserve('k1')->ok(), 'the key was never used');
    }

    public function testAnIntegrityExceptionWithNoMatchingKeyIsUnavailable(): void
    {
        $real = $this->container['db'];
        $this->withAttemptsTable(fn ($db, $name) => new class($db, $name) extends \PicoDb\Table {
            public function persist(array $data)
            {
                $e = new \PDOException('integrity');
                (new \ReflectionProperty(\Exception::class, 'code'))->setValue($e, '23000');
                throw $e;
            }
        });
        $r = $this->reserve('k1');
        $this->container['db'] = $real;

        $this->assertSame(Reservation::UNAVAILABLE, $r->outcome);
        $this->assertTrue($this->reserve('k1')->ok());
    }

    public function testAFailedReservationLogsTheExceptionClassNotItsMessage(): void
    {
        $real = $this->container['db'];
        $this->container['db'] = new class($real) {
            public function __construct(private $db) {}

            public function table($name)
            {
                if ($name !== 'aiconnector_attempts') {
                    return $this->db->table($name);
                }
                return new class($this->db, $name) extends \PicoDb\Table {
                    public function persist(array $data)
                    {
                        throw new \UnexpectedValueException('secret-host db1.internal password=hunter2');
                    }
                };
            }

            public function __call($method, $args)
            {
                return $this->db->$method(...$args);
            }
        };
        $logger = new class {
            public array $lines = [];
            public function info($line) { $this->lines[] = $line; }
            public function debug($line) {}
        };
        $realLogger = $this->container['logger'];
        $this->container['logger'] = $logger;

        $r = $this->reserve('k1');
        $this->container['db'] = $real;
        $this->container['logger'] = $realLogger;

        $this->assertSame(Reservation::UNAVAILABLE, $r->outcome);
        $log = implode("\n", $logger->lines);
        $this->assertStringContainsString('UnexpectedValueException', $log);
        $this->assertStringNotContainsString('hunter2', $log);
        $this->assertStringNotContainsString('db1.internal', $log);
    }

    public function testConsumeIsUnchangedAndOpensNoAttempt(): void
    {
        $this->assertNull($this->quota()->consume($this->ana, 'summary', 'x'));
        $this->assertSame(1, $this->counted());
        $this->assertCount(0, $this->attempts());
    }
}
