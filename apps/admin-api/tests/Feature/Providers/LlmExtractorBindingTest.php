<?php

declare(strict_types=1);

use App\Application\Conferences\Discovery\ListConferenceUrlsExtractor;
use App\Infrastructure\LlmExtraction\BedrockListConferenceUrlsExtractor;
use App\Infrastructure\LlmExtraction\MockListConferenceUrlsExtractor;

/**
 * AppServiceProvider の LLM Extractor binding テスト (Issue #206 #2)。
 *
 * URL 列挙 (ListConferenceUrlsExtractor) のモデル分離を検証する:
 * - llm.model_discovery 設定時はそれを使う (= Haiku 化、コスト削減)
 * - 未設定時は llm.model に fallback (= 後方互換、env 未設定の環境で壊れない)
 *
 * modelId は private プロパティのため reflection で読む (= binding の配線検証が目的)。
 */
function resolveDiscoveryExtractorModelId(): string
{
    $extractor = app(ListConferenceUrlsExtractor::class);
    expect($extractor)->toBeInstanceOf(BedrockListConferenceUrlsExtractor::class);

    $prop = new ReflectionProperty(BedrockListConferenceUrlsExtractor::class, 'modelId');

    $value = $prop->getValue($extractor);
    assert(is_string($value));

    return $value;
}

it('llm.model_discovery 設定時は URL 列挙 Extractor がそのモデルを使う', function () {
    // Given: provider=bedrock + discovery 専用モデル設定
    config()->set('llm.provider', 'bedrock');
    config()->set('llm.model', 'jp.anthropic.claude-sonnet-4-6');
    config()->set('llm.model_discovery', 'jp.anthropic.claude-haiku-4-5-20251001-v1:0');

    // When/Then: discovery 用モデルが採用される
    expect(resolveDiscoveryExtractorModelId())->toBe('jp.anthropic.claude-haiku-4-5-20251001-v1:0');
});

it('llm.model_discovery 未設定 (null) 時は llm.model に fallback する', function () {
    // Given: discovery モデル未設定
    config()->set('llm.provider', 'bedrock');
    config()->set('llm.model', 'jp.anthropic.claude-sonnet-4-6');
    config()->set('llm.model_discovery', null);

    // When/Then: 共通モデルに fallback (= LLM_MODEL_DISCOVERY 未設定の環境でも壊れない)
    expect(resolveDiscoveryExtractorModelId())->toBe('jp.anthropic.claude-sonnet-4-6');
});

it('llm.model_discovery が空文字列の場合も llm.model に fallback する (= 防御)', function () {
    // Given: 空文字列 (= env 定義はあるが値が空のケース)
    config()->set('llm.provider', 'bedrock');
    config()->set('llm.model', 'jp.anthropic.claude-sonnet-4-6');
    config()->set('llm.model_discovery', '');

    // When/Then
    expect(resolveDiscoveryExtractorModelId())->toBe('jp.anthropic.claude-sonnet-4-6');
});

it('provider=mock 時は model_discovery 設定に関わらず Mock を返す', function () {
    // Given
    config()->set('llm.provider', 'mock');
    config()->set('llm.model_discovery', 'jp.anthropic.claude-haiku-4-5-20251001-v1:0');

    // When/Then
    expect(app(ListConferenceUrlsExtractor::class))->toBeInstanceOf(MockListConferenceUrlsExtractor::class);
});
