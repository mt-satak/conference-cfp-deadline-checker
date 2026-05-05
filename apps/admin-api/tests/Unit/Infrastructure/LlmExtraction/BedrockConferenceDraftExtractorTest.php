<?php

use App\Application\Conferences\Extraction\ConferenceDraft;
use App\Application\Conferences\Extraction\LlmExtractionFailedException;
use App\Domain\Conferences\ConferenceFormat;
use App\Infrastructure\LlmExtraction\BedrockConferenceDraftExtractor;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use Aws\Command;
use Aws\Result;

/**
 * BedrockConferenceDraftExtractor のユニットテスト (Issue #40 Phase 3 PR-2)。
 *
 * Bedrock Converse API + Tool Use のレスポンス → ConferenceDraft マッピング、
 * 例外処理、Tool Use ではないレスポンスへの fail-safe 等を検証。
 *
 * BedrockRuntimeClient は Mockery で差し替え。実 Bedrock は呼ばない。
 */
/**
 * @param  array<string, mixed>  $output
 */
function makeBedrockClientReturning(array $output): BedrockRuntimeClient
{
    $client = Mockery::mock(BedrockRuntimeClient::class);
    $client->shouldReceive('converse')
        ->once()
        ->andReturn(new Result($output));

    /** @var BedrockRuntimeClient $client */
    return $client;
}

it('Tool Use レスポンスから ConferenceDraft をマッピングする', function () {
    // Given: extract_conference_draft Tool Use 形式の Bedrock レスポンス
    $output = [
        'output' => [
            'message' => [
                'role' => 'assistant',
                'content' => [
                    [
                        'toolUse' => [
                            'toolUseId' => 'tooluse_1',
                            'name' => 'extract_conference_draft',
                            'input' => [
                                'name' => 'PHP Conference 2026',
                                'trackName' => null,
                                'officialUrl' => 'https://phpcon.example.com/2026',
                                'cfpUrl' => 'https://fortee.jp/phpcon-2026/cfp',
                                'eventStartDate' => '2026-07-20',
                                'eventEndDate' => '2026-07-20',
                                'venue' => '大田区産業プラザ PiO',
                                'format' => 'offline',
                                'cfpStartDate' => null,
                                'cfpEndDate' => '2026-05-20',
                                'categorySlugs' => ['php', 'backend'],
                                'description' => '国内最大の PHP カンファレンス',
                                'themeColor' => '#777BB4',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'stopReason' => 'tool_use',
    ];
    $client = makeBedrockClientReturning($output);

    // When
    $extractor = new BedrockConferenceDraftExtractor(
        client: $client,
        modelId: 'anthropic.claude-sonnet-4-6',
        availableSlugs: ['php', 'backend', 'frontend'],
    );
    $draft = $extractor->extract(
        'https://phpcon.example.com/2026',
        '<html><body><main>PHP Conference 2026</main></body></html>',
    );

    // Then
    expect($draft)->toBeInstanceOf(ConferenceDraft::class);
    expect($draft->sourceUrl)->toBe('https://phpcon.example.com/2026');
    expect($draft->name)->toBe('PHP Conference 2026');
    expect($draft->officialUrl)->toBe('https://phpcon.example.com/2026');
    expect($draft->eventStartDate)->toBe('2026-07-20');
    expect($draft->cfpEndDate)->toBe('2026-05-20');
    expect($draft->format)->toBe(ConferenceFormat::Offline);
    expect($draft->categorySlugs)->toBe(['php', 'backend']);
    expect($draft->themeColor)->toBe('#777BB4');
});

it('Tool Use レスポンスの null フィールドはそのまま null として ConferenceDraft に渡る', function () {
    // Given: 多くのフィールドが null の最小レスポンス
    $output = [
        'output' => [
            'message' => [
                'content' => [
                    [
                        'toolUse' => [
                            'toolUseId' => 'tooluse_2',
                            'name' => 'extract_conference_draft',
                            'input' => [
                                'name' => 'Future Conf 2027',
                                'trackName' => null,
                                'officialUrl' => null,
                                'cfpUrl' => null,
                                'eventStartDate' => null,
                                'eventEndDate' => null,
                                'venue' => null,
                                'format' => null,
                                'cfpStartDate' => null,
                                'cfpEndDate' => null,
                                'categorySlugs' => [],
                                'description' => null,
                                'themeColor' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
    $client = makeBedrockClientReturning($output);

    // When
    $extractor = new BedrockConferenceDraftExtractor($client, 'anthropic.claude-sonnet-4-6', ['php']);
    $draft = $extractor->extract('https://x.example.com', '<html></html>');

    // Then
    expect($draft->name)->toBe('Future Conf 2027');
    expect($draft->format)->toBeNull();
    expect($draft->cfpEndDate)->toBeNull();
    expect($draft->categorySlugs)->toBe([]);
});

it('Bedrock SDK が例外を投げると LlmExtractionFailedException::modelError', function () {
    // Given: BedrockRuntimeClient が SDK 例外を投げる
    $client = Mockery::mock(BedrockRuntimeClient::class);
    $client->shouldReceive('converse')
        ->once()
        ->andThrow(new BedrockRuntimeException(
            'Throttled',
            new Command('Converse'),
            ['code' => 'ThrottlingException'],
        ));

    // When/Then
    $extractor = new BedrockConferenceDraftExtractor($client, 'anthropic.claude-sonnet-4-6', []);
    expect(fn () => $extractor->extract('https://x.example.com', '<html></html>'))
        ->toThrow(LlmExtractionFailedException::class);
});

it('レスポンスに toolUse が無い (LLM がテキストで返した) と invalidResponse', function () {
    // Given: text のみの content (Tool Use を呼ばずに自然言語で返した想定)
    $output = [
        'output' => [
            'message' => [
                'content' => [
                    ['text' => 'すみません、抽出できませんでした'],
                ],
            ],
        ],
    ];
    $client = makeBedrockClientReturning($output);

    // When/Then
    $extractor = new BedrockConferenceDraftExtractor($client, 'anthropic.claude-sonnet-4-6', []);
    expect(fn () => $extractor->extract('https://x.example.com', '<html></html>'))
        ->toThrow(LlmExtractionFailedException::class, 'did not match schema');
});

it('Tool Use の format に未知値があると ConferenceDraft の format は null (= fail-safe)', function () {
    // Given: format='live-streaming' のような enum 外の値
    $output = [
        'output' => [
            'message' => [
                'content' => [
                    [
                        'toolUse' => [
                            'toolUseId' => 'x',
                            'name' => 'extract_conference_draft',
                            'input' => [
                                'name' => 'X',
                                'officialUrl' => 'https://x.example.com',
                                'format' => 'live-streaming',
                                'categorySlugs' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
    $client = makeBedrockClientReturning($output);

    // When
    $extractor = new BedrockConferenceDraftExtractor($client, 'anthropic.claude-sonnet-4-6', []);
    $draft = $extractor->extract('https://x.example.com', '<html></html>');

    // Then: 例外を投げず、format は null として fail-safe
    expect($draft->format)->toBeNull();
    expect($draft->name)->toBe('X');
});

it('Tool Use の categorySlugs から availableSlugs に含まれないものを除外する', function () {
    // Given: LLM が "fictitious-slug" を含めて返してきた
    $output = [
        'output' => [
            'message' => [
                'content' => [
                    [
                        'toolUse' => [
                            'toolUseId' => 'x',
                            'name' => 'extract_conference_draft',
                            'input' => [
                                'name' => 'X',
                                'officialUrl' => 'https://x.example.com',
                                'categorySlugs' => ['php', 'fictitious-slug', 'backend'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
    $client = makeBedrockClientReturning($output);

    // When: 利用可能 slug は php / backend のみ
    $extractor = new BedrockConferenceDraftExtractor($client, 'anthropic.claude-sonnet-4-6', ['php', 'backend', 'frontend']);
    $draft = $extractor->extract('https://x.example.com', '<html></html>');

    // Then: 未知 slug は捨てられる
    expect($draft->categorySlugs)->toBe(['php', 'backend']);
});

it('converse 呼出時のリクエスト body に modelId / messages / tools / system が含まれる', function () {
    // Given: 引数を捕捉する mock
    $captured = null;
    $client = Mockery::mock(BedrockRuntimeClient::class);
    $client->shouldReceive('converse')
        ->once()
        ->with(Mockery::on(function ($args) use (&$captured) {
            $captured = $args;

            return true;
        }))
        ->andReturn(new Result([
            'output' => ['message' => ['content' => [
                ['toolUse' => ['toolUseId' => 'x', 'name' => 'extract_conference_draft',
                    'input' => ['name' => 'X', 'categorySlugs' => []]]],
            ]]],
        ]));

    // When
    $extractor = new BedrockConferenceDraftExtractor(
        client: $client,
        modelId: 'anthropic.claude-sonnet-4-6-test',
        availableSlugs: ['php', 'frontend'],
    );
    $extractor->extract('https://test.example.com', '<html><main>html content</main></html>');

    // Then
    /** @var array<string, mixed> $captured */
    expect($captured)->toBeArray();
    expect($captured['modelId'])->toBe('anthropic.claude-sonnet-4-6-test');
    expect($captured['system'])->toBeArray();
    expect($captured['messages'])->toBeArray();
    // Tool Use が toolConfig に登録されている
    expect($captured['toolConfig']['tools'])->toBeArray();
    expect($captured['toolConfig']['tools'][0]['toolSpec']['name'])->toBe('extract_conference_draft');
    // 利用可能 slug が system prompt に含まれている
    $systemText = $captured['system'][0]['text'] ?? '';
    expect($systemText)->toContain('php');
    expect($systemText)->toContain('frontend');
});
