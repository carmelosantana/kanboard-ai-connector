<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\AiConnector\Model\AiQuota;
use KanboardTests\units\Base;

/**
 * The shared AI spend guard: a per-user hourly request quota and an input-length cap,
 * checked and counted before each provider call by every consuming plugin.
 */
class AiQuotaTest extends Base
{
    private int $now = 1_800_000_000;
    private int $ana;
    private int $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__."/../Schema/$driver.php";
        \Kanboard\Plugin\AiConnector\Schema\version_1($this->container['db']->getConnection());
        \Kanboard\Plugin\AiConnector\Schema\version_2($this->container['db']->getConnection());
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

    public function testDefaultsAreThirtyRequestsAnHourAndThirtyTwoThousandCharacters(): void
    {
        $this->assertSame([AiQuota::REQUESTS_PER_HOUR => 30, AiQuota::MAX_INPUT_CHARS => 32000, AiQuota::MAX_CONCURRENT => 3], $this->quota()->values());
    }

    public function testStoredValuesThatAreNotPositiveWholeNumbersReadAsTheDefault(): void
    {
        foreach (['0', '-3', 'abc', '2.5', ''] as $bad) {
            $this->setting(AiQuota::REQUESTS_PER_HOUR, $bad);
            $this->assertSame(30, $this->quota()->requestsPerHour(), $bad);
        }
        $this->setting(AiQuota::REQUESTS_PER_HOUR, '5');
        $this->assertSame(5, $this->quota()->requestsPerHour());
    }

    public function testAllowsUpToTheHourlyLimitThenRefusesWithAMessage(): void
    {
        $this->setting(AiQuota::REQUESTS_PER_HOUR, '3');
        $quota = $this->quota();
        for ($i = 0; $i < 3; $i++) {
            $this->assertNull($quota->consume($this->ana, 'kensho.clarify', 'hello'));
        }
        $refusal = $quota->consume($this->ana, 'kensho.clarify', 'hello');
        $this->assertIsString($refusal);
        $this->assertStringContainsString('3', $refusal);
        $this->assertSame(3, $quota->usedThisHour($this->ana), 'a refused request is not counted');
    }

    public function testTheQuotaIsSharedAcrossFeaturesButNotAcrossUsers(): void
    {
        $this->setting(AiQuota::REQUESTS_PER_HOUR, '2');
        $quota = $this->quota();
        $this->assertNull($quota->consume($this->ana, 'kensho.clarify', 'a'));
        $this->assertNull($quota->consume($this->ana, 'timeinvoice.cover_note', 'b'));
        $this->assertNotNull($quota->consume($this->ana, 'subtaskgenerator.generate', 'c'));
        $this->assertNull($quota->consume($this->bob, 'kensho.clarify', 'd'));
    }

    public function testTheWindowSlidesSoOldRequestsStopCounting(): void
    {
        $this->setting(AiQuota::REQUESTS_PER_HOUR, '2');
        $quota = $this->quota();
        $this->assertNull($quota->consume($this->ana, 'f', 'x'));
        $this->now += 1800;
        $this->assertNull($quota->consume($this->ana, 'f', 'x'));
        $this->assertNotNull($quota->consume($this->ana, 'f', 'x'));
        $this->now += 1801; // the first request is now more than an hour old
        $this->assertNull($quota->consume($this->ana, 'f', 'x'));
        $this->assertNotNull($quota->consume($this->ana, 'f', 'x'));
    }

    public function testOldRowsArePrunedSoTheTableStaysSmall(): void
    {
        $quota = $this->quota();
        $quota->consume($this->ana, 'f', 'x');
        $quota->consume($this->bob, 'f', 'x');
        $this->now += 3601;
        $quota->consume($this->ana, 'f', 'x');
        $this->assertSame(1, $this->container['db']->table(AiQuota::TABLE)->count());
    }

    public function testInputLongerThanTheCapIsRefusedAndNotCounted(): void
    {
        $this->setting(AiQuota::MAX_INPUT_CHARS, '10');
        $quota = $this->quota();
        $this->assertNull($quota->consume($this->ana, 'f', str_repeat('é', 10)), 'the cap counts characters, not bytes');
        $refusal = $quota->consume($this->ana, 'f', str_repeat('a', 11));
        $this->assertIsString($refusal);
        $this->assertStringContainsString('10', $refusal);
        $this->assertSame(1, $quota->usedThisHour($this->ana));
    }

    public function testInputCanBeAListOfPartsWhoseLengthsAreSummed(): void
    {
        $this->setting(AiQuota::MAX_INPUT_CHARS, '10');
        $quota = $this->quota();
        $this->assertNull($quota->consume($this->ana, 'f', ['12345', '12345']));
        $this->assertNotNull($quota->consume($this->ana, 'f', ['12345', '123456']));
    }

    public function testAnonymousOrUnknownUsersAreRefused(): void
    {
        $this->assertNotNull($this->quota()->consume(0, 'f', 'x'));
    }

    public function testSaveStoresWholeNumbersOfOneOrMore(): void
    {
        $quota = $this->quota();
        $this->assertSame([], $quota->save([AiQuota::REQUESTS_PER_HOUR => ' 12 ', AiQuota::MAX_INPUT_CHARS => '5000', AiQuota::MAX_CONCURRENT => '4']));
        $this->container['memoryCache']->flush();
        $this->assertSame([AiQuota::REQUESTS_PER_HOUR => 12, AiQuota::MAX_INPUT_CHARS => 5000, AiQuota::MAX_CONCURRENT => 4], $this->quota()->values());
    }

    public function testSaveRejectsBadValuesAndSavesNothing(): void
    {
        $errors = $this->quota()->save([AiQuota::REQUESTS_PER_HOUR => '0', AiQuota::MAX_INPUT_CHARS => '5000', AiQuota::MAX_CONCURRENT => '3']);
        $this->assertArrayHasKey(AiQuota::REQUESTS_PER_HOUR, $errors);
        $this->container['memoryCache']->flush();
        $this->assertSame(32000, $this->quota()->maxInputChars());

        $errors = $this->quota()->save([AiQuota::REQUESTS_PER_HOUR => '5', AiQuota::MAX_INPUT_CHARS => '9999999999', AiQuota::MAX_CONCURRENT => '3']);
        $this->assertArrayHasKey(AiQuota::MAX_INPUT_CHARS, $errors);
        $errors = $this->quota()->save([AiQuota::REQUESTS_PER_HOUR => '5']);
        $this->assertArrayHasKey(AiQuota::MAX_INPUT_CHARS, $errors);
    }

    public function testEachUserGetsOneLockRowThatSerialisesTheirChecks(): void
    {
        $quota = $this->quota();
        $quota->consume($this->ana, 'f', 'x');
        $quota->consume($this->ana, 'f', 'x');
        $quota->consume($this->bob, 'f', 'x');
        $db = $this->container['db'];
        $this->assertSame(1, $db->table(AiQuota::LOCK_TABLE)->eq('user_id', $this->ana)->count());
        $this->assertSame(1, $db->table(AiQuota::LOCK_TABLE)->eq('user_id', $this->bob)->count());
    }

    public function testNoTransactionIsLeftOpenWhetherAllowedOrRefused(): void
    {
        $this->setting(AiQuota::REQUESTS_PER_HOUR, '1');
        $pdo = $this->container['db']->getConnection();
        $quota = $this->quota();
        $this->assertNull($quota->consume($this->ana, 'f', 'x'));
        $this->assertFalse($pdo->inTransaction());
        $this->assertNotNull($quota->consume($this->ana, 'f', 'x'));
        $this->assertFalse($pdo->inTransaction());
    }

    public function testInsideACallersTransactionItFailsClosed(): void
    {
        // A caller's rollback would erase the count after the provider was paid: refuse to run there.
        $db = $this->container['db'];
        $db->startTransaction();
        try {
            $this->quota()->consume($this->ana, 'f', 'x');
            $this->fail('expected a LogicException');
        } catch (\LogicException $e) {
            $this->assertTrue($db->getConnection()->inTransaction(), 'the caller\'s transaction is left alone');
        } finally {
            $db->cancelTransaction();
        }
        $this->assertSame(0, $db->table(AiQuota::TABLE)->count());
    }

    public function testAnUnknownUserIdIsRefusedAndNothingIsCounted(): void
    {
        $this->assertIsString($this->quota()->consume(987654, 'f', 'x'));
        $db = $this->container['db'];
        $this->assertSame(0, $db->table(AiQuota::TABLE)->count());
        $this->assertSame(0, $db->table(AiQuota::LOCK_TABLE)->count());
    }

    public function testBothLimitsHaveACeiling(): void
    {
        $quota = $this->quota();
        $this->assertSame([], $quota->save([AiQuota::REQUESTS_PER_HOUR => '10000', AiQuota::MAX_INPUT_CHARS => '1000000', AiQuota::MAX_CONCURRENT => '20']));
        $this->assertArrayHasKey(AiQuota::REQUESTS_PER_HOUR, $quota->save([AiQuota::REQUESTS_PER_HOUR => '10001', AiQuota::MAX_INPUT_CHARS => '5000', AiQuota::MAX_CONCURRENT => '3']));
        $this->assertArrayHasKey(AiQuota::MAX_INPUT_CHARS, $quota->save([AiQuota::REQUESTS_PER_HOUR => '5', AiQuota::MAX_INPUT_CHARS => '1000001', AiQuota::MAX_CONCURRENT => '3']));

        // A stored value above the ceiling (set before 1.3.0's checks, or by hand) reads as the default.
        $this->setting(AiQuota::REQUESTS_PER_HOUR, '999999999');
        $this->setting(AiQuota::MAX_INPUT_CHARS, '999999999');
        $this->setting(AiQuota::MAX_CONCURRENT, '999999999');
        $this->assertSame([AiQuota::REQUESTS_PER_HOUR => 30, AiQuota::MAX_INPUT_CHARS => 32000, AiQuota::MAX_CONCURRENT => 3], $this->quota()->values());
    }

    public function testMaxConcurrentRejectsZeroAndAboveTwenty(): void
    {
        $ok = [AiQuota::REQUESTS_PER_HOUR => '5', AiQuota::MAX_INPUT_CHARS => '5000'];
        $quota = $this->quota();
        $this->assertArrayHasKey(AiQuota::MAX_CONCURRENT, $quota->save($ok + [AiQuota::MAX_CONCURRENT => '0']));
        $this->assertArrayHasKey(AiQuota::MAX_CONCURRENT, $quota->save($ok + [AiQuota::MAX_CONCURRENT => '21']));
        $this->assertSame([], $quota->save($ok + [AiQuota::MAX_CONCURRENT => '20']));
        $this->container['memoryCache']->flush();
        $this->assertSame(20, $this->quota()->maxConcurrent());
    }
}
