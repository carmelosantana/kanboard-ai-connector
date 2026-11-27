<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use KanboardTests\units\Base;

/** The aiconnector:features hook: plugins register their AI features; AiConnector lists them. */
class FeatureRegistryTest extends Base
{
    /** @var list<array{string,string}> level, message */
    public array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $test = $this;
        $this->container['logger'] = new class($test) extends \Psr\Log\AbstractLogger {
            public function __construct(private $test) {}
            public function log($level, $message, array $context = []): void
            {
                $this->test->logged[] = [(string) $level, (string) $message];
            }
        };
    }

    private function listen(callable $listener): void
    {
        $this->container['hook']->on(ProviderRegistry::FEATURES_HOOK, $listener);
    }

    private function features(): array
    {
        return (new ProviderRegistry($this->container))->features();
    }

    public function testNoListenersMeansNoFeatures(): void
    {
        $this->assertSame([], $this->features());
    }

    public function testListenersMergeKeyedById(): void
    {
        $this->listen(fn () => [['id' => 'kensho.clarify', 'label' => 'Clarify', 'plugin' => 'Kensho']]);
        $this->listen(fn () => [['id' => 'timereport.summary', 'label' => 'Summary', 'plugin' => 'TimeReport']]);

        $this->assertSame([
            'kensho.clarify' => ['id' => 'kensho.clarify', 'label' => 'Clarify', 'plugin' => 'Kensho'],
            'timereport.summary' => ['id' => 'timereport.summary', 'label' => 'Summary', 'plugin' => 'TimeReport'],
        ], $this->features());
    }

    public function testInvalidIdsAreDropped(): void
    {
        $this->listen(fn () => [
            ['id' => 'Bad Id', 'label' => 'x', 'plugin' => 'P'],
            ['id' => str_repeat('a', 65), 'label' => 'x', 'plugin' => 'P'],
            ['id' => '.leading', 'label' => 'x', 'plugin' => 'P'],
            ['id' => "a.b\n", 'label' => 'x', 'plugin' => 'P'],
            ['id' => str_repeat('a', 64), 'label' => 'ok', 'plugin' => 'P'],
        ]);

        $this->assertSame([str_repeat('a', 64)], array_keys($this->features()));
    }

    public function testDuplicateIdKeepsTheFirst(): void
    {
        $this->listen(fn () => [['id' => 'a.b', 'label' => 'First', 'plugin' => 'P']]);
        $this->listen(fn () => [['id' => 'a.b', 'label' => 'Second', 'plugin' => 'Q']]);

        $this->assertSame('First', $this->features()['a.b']['label']);
    }

    public function testAThrowingListenerIsSkippedAndLogged(): void
    {
        $this->listen(function () { throw new \RuntimeException('boom'); });
        $this->listen(fn () => [['id' => 'a.b', 'label' => 'Ok', 'plugin' => 'P']]);

        $this->assertSame(['a.b'], array_keys($this->features()));
        $errors = array_filter($this->logged, fn ($l) => $l[0] === 'error' && str_contains($l[1], 'aiconnector:features'));
        $this->assertCount(1, $errors);
    }

    public function testMalformedListenersAndEntriesAreIgnored(): void
    {
        $this->listen(fn () => 'not an array');
        $this->listen(fn () => [
            'not an entry',
            ['id' => 'no.label', 'plugin' => 'P'],
            ['id' => 'no.plugin', 'label' => 'x'],
            ['id' => 'bad.label', 'label' => ['x'], 'plugin' => 'P'],
            ['id' => 'good.one', 'label' => 'Good', 'plugin' => 'P', 'extra' => 'dropped'],
        ]);

        $this->assertSame(['good.one' => ['id' => 'good.one', 'label' => 'Good', 'plugin' => 'P']], $this->features());
    }
}
