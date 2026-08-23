<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\AiConnector\Model\ModelContextTable;
use KanboardTests\units\Base;

/** Task 1 — curated context-window table resolver. Pure, no network, no DB. */
class ModelContextTableTest extends Base
{
    public function testExactIdMatch(): void
    {
        $this->assertSame(128000, ModelContextTable::resolve('openai', 'gpt-4o'));
        $this->assertSame(1047576, ModelContextTable::resolve('openai', 'gpt-4.1-mini'));
        $this->assertSame(1050000, ModelContextTable::resolve('openai', 'gpt-5.6-sol'));
        $this->assertSame(400000, ModelContextTable::resolve('openai', 'gpt-5.6-cyber'));
        $this->assertSame(1000000, ModelContextTable::resolve('anthropic', 'claude-opus-5'));
    }

    public function testOpenAiResponsesSharesOpenAiPartition(): void
    {
        $this->assertSame(400000, ModelContextTable::resolve('openai_responses', 'gpt-5'));
    }

    public function testDatedAnthropicIdNormalizesToExact(): void
    {
        $this->assertSame(200000, ModelContextTable::resolve('anthropic', 'claude-sonnet-4-20250514'));
        $this->assertSame(200000, ModelContextTable::resolve('anthropic', 'claude-opus-4-1-20250805'));
    }

    public function testLongestPrefixFamilyWins(): void
    {
        // gpt-5.6 (1.05M) must beat gpt-5 (400k) for an unknown gpt-5.6-* id
        $this->assertSame(1050000, ModelContextTable::resolve('openai', 'gpt-5.6-experimental'));
        // grok-4-fast (2M) must beat grok-4 (256k)
        $this->assertSame(2000000, ModelContextTable::resolve('grok', 'grok-4-fast-reasoning'));
        $this->assertSame(256000, ModelContextTable::resolve('grok', 'grok-4-0709'));
    }

    public function testOllamaTagStrippedThenFamilyMatched(): void
    {
        $this->assertSame(131072, ModelContextTable::resolve('ollama', 'llama3.1:8b-instruct-q4_K_M'));
        $this->assertSame(8192, ModelContextTable::resolve('ollama', 'gemma2:2b'));
        $this->assertSame(32768, ModelContextTable::resolve('ollama', 'qwen2.5-coder:7b'));
        $this->assertSame(40960, ModelContextTable::resolve('ollama', 'qwen3'));
    }

    public function testMistralMediumFamilyFloorVsExactId(): void
    {
        // unknown mistral-medium-* -> conservative family floor 128000
        $this->assertSame(128000, ModelContextTable::resolve('mistral', 'mistral-medium-2999'));
        // exact id preserved -> 256000
        $this->assertSame(256000, ModelContextTable::resolve('mistral', 'mistral-medium-2604'));
    }

    public function testProviderDefaultWhenUnknownId(): void
    {
        $this->assertSame(200000, ModelContextTable::resolve('anthropic', 'totally-unknown'));
        $this->assertSame(128000, ModelContextTable::resolve('openai', 'totally-unknown'));
        $this->assertSame(1048576, ModelContextTable::resolve('gemini', 'totally-unknown'));
        $this->assertSame(128000, ModelContextTable::resolve('mistral', 'totally-unknown'));
        $this->assertSame(131072, ModelContextTable::resolve('grok', 'totally-unknown'));
        $this->assertSame(8192, ModelContextTable::resolve('ollama', 'totally-unknown'));
    }

    public function testGlobalDefaultForUnknownProvider(): void
    {
        $this->assertSame(8192, ModelContextTable::resolve('llamacpp', 'whatever'));
        $this->assertSame(8192, ModelContextTable::GLOBAL_DEFAULT);
    }

    public function testLastVerifiedPresent(): void
    {
        $this->assertSame('2026-08-23', ModelContextTable::LAST_VERIFIED);
    }
}
