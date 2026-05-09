<?php

use App\Application\Conferences\Extraction\ConferenceDraft;
use App\Application\Conferences\Extraction\LlmExtractionFailedException;
use App\Domain\Conferences\ConferenceFormat;
use App\Infrastructure\LlmExtraction\BedrockConferenceDraftExtractor;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use Aws\Command;
use Aws\Result;
use Illuminate\Support\Facades\Log;

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

it('成功時に Token 消費 / latency / モデル ID を構造化ログに残す (PR-4 観測性)', function () {
    // Given: usage を含む Bedrock レスポンス
    $output = [
        'output' => ['message' => ['content' => [
            ['toolUse' => ['toolUseId' => 'x', 'name' => 'extract_conference_draft',
                'input' => ['name' => 'X', 'categorySlugs' => []]]],
        ]]],
        'usage' => ['inputTokens' => 9842, 'outputTokens' => 412, 'totalTokens' => 10254],
    ];
    $client = makeBedrockClientReturning($output);

    // ログ期待: info 1 件 (channel=llm.extraction, source_host (= host のみ、Issue #177 #4)、model_id, tokens)
    Log::shouldReceive('info')
        ->once()
        ->with(
            'conference draft extraction succeeded',
            Mockery::on(function (array $context): bool {
                return ($context['source_host'] ?? null) === 'test.example.com'
                    && ! array_key_exists('source_url', $context)
                    && ($context['provider'] ?? null) === 'bedrock'
                    && ($context['model_id'] ?? null) === 'anthropic.claude-sonnet-4-6'
                    && ($context['input_tokens'] ?? null) === 9842
                    && ($context['output_tokens'] ?? null) === 412
                    && is_int($context['elapsed_ms'] ?? null);
            }),
        );

    // When
    $extractor = new BedrockConferenceDraftExtractor($client, 'anthropic.claude-sonnet-4-6', []);
    $extractor->extract('https://test.example.com', '<html></html>');
});

it('source_host は path / query / fragment を切って host のみ記録する (Issue #177 #4)', function () {
    // Given: path / query / fragment 付きの URL
    $output = [
        'output' => ['message' => ['content' => [
            ['toolUse' => ['toolUseId' => 'x', 'name' => 'extract_conference_draft',
                'input' => ['name' => 'X', 'categorySlugs' => []]]],
        ]]],
    ];
    $client = makeBedrockClientReturning($output);

    Log::shouldReceive('info')
        ->once()
        ->with(
            'conference draft extraction succeeded',
            Mockery::on(function (array $context): bool {
                // host 部分のみ。path "/secret/admin/probe?token=xyz" は記録されない
                return ($context['source_host'] ?? null) === 'phpcon.example.com'
                    && ! array_key_exists('source_url', $context);
            }),
        );

    // When: path / query / fragment 付き
    $extractor = new BedrockConferenceDraftExtractor($client, 'anthropic.claude-sonnet-4-6', []);
    $extractor->extract('https://phpcon.example.com/secret/admin/probe?token=xyz#section', '<html></html>');
});

it('host 抽出に失敗した URL は source_host を null にする (= ログ汚染防止)', function () {
    // Given: scheme / host が無い不正 URL (admin が誤入力した想定)
    $output = [
        'output' => ['message' => ['content' => [
            ['toolUse' => ['toolUseId' => 'x', 'name' => 'extract_conference_draft',
                'input' => ['name' => 'X', 'categorySlugs' => []]]],
        ]]],
    ];
    $client = makeBedrockClientReturning($output);

    Log::shouldReceive('info')
        ->once()
        ->with(
            'conference draft extraction succeeded',
            Mockery::on(function (array $context): bool {
                // host が抽出できないので key 存在 & 値 null。元の文字列はログに乗らない
                return array_key_exists('source_host', $context)
                    && $context['source_host'] === null
                    && ! array_key_exists('source_url', $context);
            }),
        );

    // When: 不正 URL
    $extractor = new BedrockConferenceDraftExtractor($client, 'anthropic.claude-sonnet-4-6', []);
    $extractor->extract('not-a-url', '<html></html>');
});

it('SDK 例外時に warning ログを残してから例外を投げる', function () {
    // Given
    $client = Mockery::mock(BedrockRuntimeClient::class);
    $client->shouldReceive('converse')
        ->once()
        ->andThrow(new BedrockRuntimeException(
            'throttled',
            new Command('Converse'),
            ['code' => 'ThrottlingException'],
        ));

    Log::shouldReceive('warning')
        ->once()
        ->with(
            'conference draft extraction failed',
            Mockery::on(function (array $context): bool {
                return ($context['source_host'] ?? null) === 'x.example.com'
                    && ! array_key_exists('source_url', $context)
                    && ($context['provider'] ?? null) === 'bedrock'
                    && str_contains($context['exception_type'] ?? '', 'BedrockRuntimeException');
            }),
        );

    // When/Then
    $extractor = new BedrockConferenceDraftExtractor($client, 'anthropic.claude-sonnet-4-6', []);
    expect(fn () => $extractor->extract('https://x.example.com', '<html></html>'))
        ->toThrow(LlmExtractionFailedException::class);
});

it('system prompt が「HTML に対応する記述が無い場合のみ null」を明記する (Issue #152 Phase 1 観測フィードバック)', function () {
    // Given: Bedrock のリクエスト引数を捕捉する mock
    // 観測結果 (5/5 件で全 diff が null) から、現状の「自信がない → null」だと過剰に null になっており、
    // HTML に存在する事実すら null で返るケースが頻発していた。
    // 改善: 「HTML に対応する記述が無い場合のみ null」という明確な判定基準にし、
    // HTML から事実を可能な限り抽出する方針へシフトする。
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
        modelId: 'anthropic.claude-sonnet-4-6',
        availableSlugs: ['php'],
    );
    $extractor->extract('https://x.example.com', '<html></html>');

    // Then
    $systemText = $captured['system'][0]['text'] ?? '';
    expect($systemText)->toContain('HTML に対応する記述が無い場合のみ null');
    expect($systemText)->toContain('HTML に書かれた事実を可能な限り抽出');
});

it('system prompt が cfpUrl 抽出方針 (a href リンク採用) を明記する (Issue #152 Phase 1 観測フィードバック)', function () {
    // Given: Bedrock のリクエスト引数を捕捉する mock
    // 観測結果から cfpUrl が常に null で返ることが分かった。原因は LLM が
    // 「公式サイトに CfP URL が直接書かれていない」と判断して推測を避けるため。
    // 改善: HTML 内に fortee.jp / connpass.com / sessionize.com への <a href> リンクが
    // ある場合はハルシネーションではなく HTML 上の事実として採用してよいことを明記する。
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
        modelId: 'anthropic.claude-sonnet-4-6',
        availableSlugs: ['php'],
    );
    $extractor->extract('https://x.example.com', '<html></html>');

    // Then
    $systemText = $captured['system'][0]['text'] ?? '';
    expect($systemText)->toContain('fortee.jp');
    expect($systemText)->toContain('connpass.com');
    expect($systemText)->toContain('<a href');
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
