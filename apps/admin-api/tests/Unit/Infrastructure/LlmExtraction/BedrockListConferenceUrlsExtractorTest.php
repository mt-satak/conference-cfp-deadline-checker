<?php

declare(strict_types=1);

use App\Application\Conferences\Extraction\LlmExtractionFailedException;
use App\Infrastructure\LlmExtraction\BedrockListConferenceUrlsExtractor;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use Aws\Command;
use Aws\Result;

/**
 * BedrockListConferenceUrlsExtractor のユニットテスト (Issue #200 PR-3)。
 *
 * Bedrock Converse API + Tool Use のレスポンス → string[] マッピング、
 * 例外処理、不正値除去 (= http://, 相対 URL) を検証。
 */
function makeListUrlsClient(array $output): BedrockRuntimeClient
{
    $client = Mockery::mock(BedrockRuntimeClient::class);
    $client->shouldReceive('converse')->once()->andReturn(new Result($output));

    /** @var BedrockRuntimeClient $client */
    return $client;
}

function listUrlsToolUse(array $urls): array
{
    return [
        'output' => [
            'message' => [
                'role' => 'assistant',
                'content' => [
                    [
                        'toolUse' => [
                            'toolUseId' => 'tooluse_1',
                            'name' => 'list_conference_urls',
                            'input' => ['urls' => $urls],
                        ],
                    ],
                ],
            ],
        ],
        'usage' => [
            'inputTokens' => 1000,
            'outputTokens' => 200,
            'totalTokens' => 1200,
        ],
    ];
}

it('Tool Use レスポンスから urls 配列をそのまま返す', function () {
    // Given
    $client = makeListUrlsClient(listUrlsToolUse([
        'https://a.example.com/2026',
        'https://b.example.com/2026',
    ]));

    $extractor = new BedrockListConferenceUrlsExtractor($client, 'jp.anthropic.claude-sonnet-4-6');

    // When
    $urls = $extractor->extract('https://fortee.jp/events', '<html></html>');

    // Then
    expect($urls)->toBe([
        'https://a.example.com/2026',
        'https://b.example.com/2026',
    ]);
});

it('http:// で始まる URL は除外する (= HTTPS のみ採用)', function () {
    // Given
    $client = makeListUrlsClient(listUrlsToolUse([
        'http://insecure.example.com/',
        'https://secure.example.com/',
    ]));

    $extractor = new BedrockListConferenceUrlsExtractor($client, 'jp.anthropic.claude-sonnet-4-6');

    // When
    $urls = $extractor->extract('https://fortee.jp/events', '<html></html>');

    // Then: http:// は除外
    expect($urls)->toBe(['https://secure.example.com/']);
});

it('相対 URL や parse_url で host が取れない不正 URL は除外する', function () {
    // Given
    $client = makeListUrlsClient(listUrlsToolUse([
        '/relative/path',  // 相対
        'https://',         // host なし
        'https://valid.example.com/',
    ]));

    $extractor = new BedrockListConferenceUrlsExtractor($client, 'jp.anthropic.claude-sonnet-4-6');

    // When
    $urls = $extractor->extract('https://fortee.jp/events', '<html></html>');

    // Then
    expect($urls)->toBe(['https://valid.example.com/']);
});

it('最大 20 件で打ち切る', function () {
    // Given: 25 件返ってくる
    $many = [];
    for ($i = 0; $i < 25; $i++) {
        $many[] = "https://conf-{$i}.example.com/";
    }
    $client = makeListUrlsClient(listUrlsToolUse($many));

    $extractor = new BedrockListConferenceUrlsExtractor($client, 'jp.anthropic.claude-sonnet-4-6');

    // When
    $urls = $extractor->extract('https://fortee.jp/events', '<html></html>');

    // Then
    expect($urls)->toHaveCount(20);
});

it('BedrockRuntimeException は LlmExtractionFailedException::modelError に変換される', function () {
    // Given
    $client = Mockery::mock(BedrockRuntimeClient::class);
    $command = Mockery::mock(Command::class);
    $client->shouldReceive('converse')
        ->once()
        ->andThrow(new BedrockRuntimeException('throttle', $command));

    /** @var BedrockRuntimeClient $client */
    $extractor = new BedrockListConferenceUrlsExtractor($client, 'jp.anthropic.claude-sonnet-4-6');

    // When/Then
    $extractor->extract('https://fortee.jp/events', '<html></html>');
})->throws(LlmExtractionFailedException::class);

it('Tool Use が含まれないレスポンスは LlmExtractionFailedException::invalidResponse', function () {
    // Given: assistant が text で返してきた (= Tool Use 強制が失敗した想定)
    $client = makeListUrlsClient([
        'output' => [
            'message' => [
                'role' => 'assistant',
                'content' => [
                    ['text' => 'sorry'],
                ],
            ],
        ],
    ]);

    $extractor = new BedrockListConferenceUrlsExtractor($client, 'jp.anthropic.claude-sonnet-4-6');

    // When/Then
    $extractor->extract('https://fortee.jp/events', '<html></html>');
})->throws(LlmExtractionFailedException::class);

it('urls キーが配列でない場合は空配列を返す (= 不正形にも fail-safe)', function () {
    // Given
    $client = makeListUrlsClient([
        'output' => [
            'message' => [
                'role' => 'assistant',
                'content' => [
                    [
                        'toolUse' => [
                            'toolUseId' => 't',
                            'name' => 'list_conference_urls',
                            'input' => ['urls' => 'not-an-array'],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $extractor = new BedrockListConferenceUrlsExtractor($client, 'jp.anthropic.claude-sonnet-4-6');

    // When
    $urls = $extractor->extract('https://fortee.jp/events', '<html></html>');

    // Then
    expect($urls)->toBe([]);
});
