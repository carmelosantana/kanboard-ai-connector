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

    public function testNormalizeMatchesTheOldRegistryRule(): void
    {
        $this->assertSame(['k' => 'v'], StructuredDecoder::normalize(['k' => 'v']));
        $this->assertSame(['k' => 'v'], StructuredDecoder::normalize($this->response('{"k":"v"}')));
        $this->assertSame([], StructuredDecoder::normalize($this->response('Sure, here you go')));
        $this->assertSame([], StructuredDecoder::normalize(null));
    }
}
