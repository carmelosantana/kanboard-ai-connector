<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\AiConnector\Stream\AiRequest;
use Kanboard\Plugin\AiConnector\Stream\AiStream;
use Kanboard\Plugin\AiConnector\Stream\Event\Error;
use Kanboard\Plugin\AiConnector\Stream\Event\Finish;
use Kanboard\Plugin\AiConnector\Stream\Event\Refused;
use Kanboard\Plugin\AiConnector\Stream\Event\Terminal;
use Kanboard\Plugin\AiConnector\Stream\Usage;
use KanboardTests\units\Base;

/** The streaming API's request and event value types: validation and shape only. */
class StreamTypesTest extends Base
{
    private function msgs(): array
    {
        return [['role' => 'user', 'content' => 'hi']];
    }

    public static function badKeys(): array
    {
        return [[''], [str_repeat('a', 65)], ['a b'], ['ü'], ["ok\n"]];
    }

    /** @dataProvider badKeys */
    public function testAttemptKeyMustMatchThePattern(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AiRequest('f', $key, $this->msgs());
    }

    public function testGoodKeyIsAccepted(): void
    {
        $r = new AiRequest('f', str_repeat('a', 64), $this->msgs());
        $this->assertSame(64, strlen($r->attemptKey));
        $this->assertNotNull(new AiRequest('f', 'A.b_c-1', $this->msgs()));
    }

    public function testTimeoutsAreClampedAndNonFiniteReadsAsDefault(): void
    {
        $mk = fn ($i, $m) => new AiRequest('f', 'k', $this->msgs(), idleTimeout: $i, maxDuration: $m);
        $r = $mk(0.0, 0.0);
        $this->assertSame(1.0, $r->idleTimeout());
        $this->assertSame(1.0, $r->maxDuration());
        $r = $mk(999.0, 999.0);
        $this->assertSame(120.0, $r->idleTimeout());
        $this->assertSame(600.0, $r->maxDuration());
        $r = $mk(INF, NAN);
        $this->assertSame(60.0, $r->idleTimeout());
        $this->assertSame(300.0, $r->maxDuration());
        $r = $mk(-INF, 30.0);
        $this->assertSame(60.0, $r->idleTimeout());
        $this->assertSame(30.0, $r->maxDuration());
        $this->assertSame(999.0, $mk(999.0, 1.0)->idleTimeout, 'raw property stays as passed');
    }

    public static function badMessages(): array
    {
        return [
            'empty' => [[]],
            'not a list' => [['a' => ['role' => 'user', 'content' => 'x']]],
            'not arrays' => [['hi']],
            'bad role' => [[['role' => 'tool', 'content' => 'x']]],
            'role not string' => [[['role' => 1, 'content' => 'x']]],
            'no content' => [[['role' => 'user']]],
            'content not string' => [[['role' => 'user', 'content' => ['x']]]],
        ];
    }

    /** @dataProvider badMessages */
    public function testMessagesAreValidated(array $messages): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AiRequest('f', 'k', $messages);
    }

    public function testAllThreeRolesPass(): void
    {
        $r = new AiRequest('f', 'k', [
            ['role' => 'system', 'content' => 's'],
            ['role' => 'user', 'content' => 'u'],
            ['role' => 'assistant', 'content' => ''],
        ]);
        $this->assertCount(3, $r->messages);
    }

    public function testSchemaMustBeJson(): void
    {
        $this->assertNull((new AiRequest('f', 'k', $this->msgs()))->schema);
        $this->assertNotNull(new AiRequest('f', 'k', $this->msgs(), '{"type":"object"}'));
        foreach (['not json', '', '5', '"x"', 'null'] as $bad) {
            try {
                new AiRequest('f', 'k', $this->msgs(), $bad);
                $this->fail("accepted schema: $bad");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testUsageClampsNegatives(): void
    {
        $u = new Usage(-1, 5, -9);
        $this->assertSame([0, 5, 0], [$u->promptTokens, $u->completionTokens, $u->totalTokens]);
    }

    public function testTerminalEventsShareTheMarker(): void
    {
        $this->assertInstanceOf(Terminal::class, new Finish('stop', 'm', null, null));
        $this->assertInstanceOf(Terminal::class, new Refused('quota', 'x'));
        $this->assertInstanceOf(Terminal::class, new Error('timeout', 'x'));
        $this->assertNotInstanceOf(Terminal::class, new \Kanboard\Plugin\AiConnector\Stream\Event\TextDelta('a'));
    }

    public function testTerminalCodesAreValidated(): void
    {
        foreach ([
            fn () => new Finish('nope', 'm', null, null),
            fn () => new Refused('nope', 'x'),
            fn () => new Error('quota', 'x'),
        ] as $make) {
            try {
                $make();
                $this->fail('accepted an unknown code');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        foreach (Finish::REASONS as $r) {
            $this->assertSame($r, (new Finish($r, 'm', null, null))->reason);
        }
        foreach (Refused::CODES as $c) {
            $this->assertSame($c, (new Refused($c, 'x'))->code);
        }
        foreach (Error::CODES as $c) {
            $this->assertSame($c, (new Error($c, 'x'))->code);
        }
    }

    public function testPublicTypesNeverMentionPhpAgents(): void
    {
        $root = dirname(__DIR__).'/Stream';
        $files = array_merge(glob("$root/Event/*.php"), ["$root/AiRequest.php", "$root/AiCancellation.php", "$root/Usage.php"]);
        $this->assertGreaterThan(10, count($files));
        foreach ($files as $file) {
            $this->assertFileExists($file);
            $this->assertStringNotContainsString('PHPAgents', file_get_contents($file), $file);
        }
    }

    public function testAiStreamPublicSignaturesNameNoPhpAgentsType(): void
    {
        $names = static function (?\ReflectionType $type) use (&$names): array {
            return match (true) {
                $type === null => [],
                $type instanceof \ReflectionNamedType => [$type->getName()],
                default => array_merge(...array_map($names, $type->getTypes())),
            };
        };

        $checked = 0;
        foreach ((new \ReflectionClass(AiStream::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $types = $names($method->getReturnType());
            foreach ($method->getParameters() as $param) {
                $types = array_merge($types, $names($param->getType()));
            }
            foreach ($types as $type) {
                $checked++;
                $this->assertStringStartsNotWith('CarmeloSantana\\', ltrim($type, '\\'), $method->class . '::' . $method->getName());
            }
        }
        $this->assertGreaterThan(0, $checked, 'some public signature types were checked');
    }
}
