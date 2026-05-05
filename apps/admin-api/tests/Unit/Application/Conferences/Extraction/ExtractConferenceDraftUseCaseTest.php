<?php

use App\Application\Conferences\Extraction\ConferenceDraft;
use App\Application\Conferences\Extraction\ConferenceDraftExtractor;
use App\Application\Conferences\Extraction\ExtractConferenceDraftUseCase;
use App\Application\Conferences\Extraction\HtmlFetcher;
use App\Application\Conferences\Extraction\HtmlFetchFailedException;
use App\Application\Conferences\Extraction\LlmExtractionFailedException;
use App\Domain\Conferences\ConferenceFormat;

/**
 * ExtractConferenceDraftUseCase の単体テスト (Issue #40 Phase 3 PR-1)。
 *
 * UseCase の責務:
 * - HtmlFetcher で URL から HTML を取得
 * - ConferenceDraftExtractor で HTML → ConferenceDraft に変換
 * - 取得失敗・抽出失敗の例外は bubble up (HTTP 層で 502 等にマップする想定)
 *
 * 双方の依存はインタフェースなので Mockery で差し替えてオーケストレーションのみ検証。
 * 実 LLM / 実 HTTP fetch は PR-2 の Infrastructure 実装テストで担当。
 */
it('HtmlFetcher → ConferenceDraftExtractor の順で呼んで Draft を返す', function () {
    // Given: fetcher は HTML を返し、extractor は Draft を返すモック
    $fetcher = Mockery::mock(HtmlFetcher::class);
    $fetcher->shouldReceive('fetch')
        ->once()
        ->with('https://phpcon.example.com/2026')
        ->andReturn('<html><body>PHP Conference 2026</body></html>');

    $expected = new ConferenceDraft(
        sourceUrl: 'https://phpcon.example.com/2026',
        name: 'PHP Conference 2026',
        format: ConferenceFormat::Offline,
    );
    $extractor = Mockery::mock(ConferenceDraftExtractor::class);
    $extractor->shouldReceive('extract')
        ->once()
        ->with('https://phpcon.example.com/2026', '<html><body>PHP Conference 2026</body></html>')
        ->andReturn($expected);

    // When
    $useCase = new ExtractConferenceDraftUseCase($fetcher, $extractor);
    $result = $useCase->execute('https://phpcon.example.com/2026');

    // Then: extractor の戻り値がそのまま返る
    expect($result)->toBe($expected);
});

it('HtmlFetcher が失敗すると ConferenceDraftExtractor は呼ばれず例外が bubble up', function () {
    // Given: fetcher が HtmlFetchFailedException を投げ、extractor は呼ばれない期待
    $fetcher = Mockery::mock(HtmlFetcher::class);
    $fetcher->shouldReceive('fetch')
        ->once()
        ->andThrow(HtmlFetchFailedException::networkError('https://x.example.com', 'connection timeout'));
    $extractor = Mockery::mock(ConferenceDraftExtractor::class);
    $extractor->shouldNotReceive('extract');

    // When/Then: 例外が外に漏れる
    $useCase = new ExtractConferenceDraftUseCase($fetcher, $extractor);
    expect(fn () => $useCase->execute('https://x.example.com'))
        ->toThrow(HtmlFetchFailedException::class);
});

it('HtmlFetchFailedException の named constructor がそれぞれ意味のあるメッセージを生成する', function () {
    // Given/When/Then: 4 種類のファクトリで例外を生成し、メッセージに識別情報が含まれる
    $a = HtmlFetchFailedException::networkError('https://x.example.com', 'connection timeout');
    expect($a->getMessage())->toContain('https://x.example.com');
    expect($a->getMessage())->toContain('connection timeout');

    $b = HtmlFetchFailedException::statusError('https://x.example.com', 503);
    expect($b->getMessage())->toContain('503');

    $c = HtmlFetchFailedException::tooLarge('https://x.example.com', 10_000_000, 5_000_000);
    expect($c->getMessage())->toContain('exceeds size limit');
    expect($c->getMessage())->toContain('5000000');

    $d = HtmlFetchFailedException::notHttps('http://insecure.example.com');
    expect($d->getMessage())->toContain('HTTPS');
    expect($d->getMessage())->toContain('insecure.example.com');
});

it('LlmExtractionFailedException の named constructor がそれぞれ識別可能なメッセージ', function () {
    // Given/When/Then
    $a = LlmExtractionFailedException::modelError('https://x.example.com', 'rate limited');
    expect($a->getMessage())->toContain('https://x.example.com');
    expect($a->getMessage())->toContain('rate limited');

    $b = LlmExtractionFailedException::invalidResponse('https://x.example.com', 'JSON schema mismatch');
    expect($b->getMessage())->toContain('did not match schema');

    $c = LlmExtractionFailedException::quotaExceeded('https://x.example.com');
    expect($c->getMessage())->toContain('quota exceeded');
});

it('ConferenceDraftExtractor が失敗すると例外が bubble up', function () {
    // Given: fetcher 成功、extractor が LlmExtractionFailedException
    $fetcher = Mockery::mock(HtmlFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->andReturn('<html></html>');
    $extractor = Mockery::mock(ConferenceDraftExtractor::class);
    $extractor->shouldReceive('extract')
        ->once()
        ->andThrow(LlmExtractionFailedException::invalidResponse('https://x.example.com', 'JSON schema mismatch'));

    // When/Then
    $useCase = new ExtractConferenceDraftUseCase($fetcher, $extractor);
    expect(fn () => $useCase->execute('https://x.example.com'))
        ->toThrow(LlmExtractionFailedException::class);
});
