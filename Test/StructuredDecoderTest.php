<?php

require_once 'tests/units/Base.php';

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Response;
use Kanboard\Plugin\AiConnector\Stream\Internal\StructuredDecoder;
use KanboardTests\units\Base;

class StructuredDecoderTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
    }

    private function response(string $content): Response
    {
        return new Response($content, ProviderFinishReason::Stop);
    }

    public function testDecodesAnObject(): void
    {
        $this->assertSame(['a' => 1, 'b' => [true]], StructuredDecoder::decode('{"a":1,"b":[true]}'));
    }

    public function testScalarOrInvalidIsNull(): void
    {
        $this->assertNull(StructuredDecoder::decode('42'));
        $this->assertNull(StructuredDecoder::decode('"x"'));
        $this->assertNull(StructuredDecoder::decode('not json'));
        $this->assertNull(StructuredDecoder::decode(''));
    }

    public function testDepthOver64IsNull(): void
    {
        $deep = str_repeat('[', 70) . str_repeat(']', 70);
        $this->assertNull(StructuredDecoder::decode($deep));
        $ok = str_repeat('[', 63) . str_repeat(']', 63);
        $this->assertIsArray(StructuredDecoder::decode($ok));
        $this->assertSame([], StructuredDecoder::normalize($this->response($deep)));
    }

    private static function nested(int $levels): array
    {
        $value = [];
        for ($i = 1; $i < $levels; $i++) {
            $value = ['n' => $value];
        }
        return $value;
    }

    public function testDecodedArrayDepthFollowsTheJsonTextRule(): void
    {
        // An array already decoded by the provider gets the same depth rule as JSON text.
        foreach ([1, 2, 63] as $levels) {
            $array = self::nested($levels);
            $this->assertIsArray(StructuredDecoder::decode(json_encode($array)), "$levels levels as text");
            $this->assertSame($array, StructuredDecoder::normalize($array), "$levels levels as an array");
        }
        foreach ([64, 65, 200] as $levels) {
            $array = self::nested($levels);
            $this->assertNull(StructuredDecoder::decode(json_encode($array, 0, 1024)), "$levels levels as text");
            $this->assertSame([], StructuredDecoder::normalize($array), "$levels levels as an array");
        }
        $list = [[1, 2], ['a' => [true]], self::nested(62)];
        $this->assertSame($list, StructuredDecoder::normalize($list), 'a wide array nested 63 deep');
        $list[] = self::nested(63);
        $this->assertSame([], StructuredDecoder::normalize($list), 'one deep branch among shallow ones');
    }

    public function testNormalizeMatchesTheOldRegistryRule(): void
    {
        $this->assertSame(['k' => 'v'], StructuredDecoder::normalize(['k' => 'v']));
        $this->assertSame(['k' => 'v'], StructuredDecoder::normalize($this->response('{"k":"v"}')));
        $this->assertSame([], StructuredDecoder::normalize($this->response('Sure, here you go')));
        $this->assertSame([], StructuredDecoder::normalize(null));
    }
}
