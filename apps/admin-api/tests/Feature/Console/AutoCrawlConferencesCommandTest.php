<?php

declare(strict_types=1);

use App\Application\Conferences\AutoCrawl\AutoCrawlConferencesUseCase;
use App\Application\Conferences\AutoCrawl\AutoCrawlResult;

/**
 * artisan conferences:auto-crawl の Feature テスト (Issue #152 Phase 1a)。
 *
 * Command 自体は薄く UseCase に委譲するだけなので、UseCase を Mock して
 * 出力 / exit code を検証する。
 */
it('artisan conferences:auto-crawl は UseCase を呼んで件数を表示する', function () {
    // Given: UseCase の戻り値をモック
    $result = new AutoCrawlResult(
        totalChecked: 3,
        diffDetected: 1,
        extractionFailed: 0,
        failedUrls: [],
    );
    $useCase = Mockery::mock(AutoCrawlConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn($result);
    app()->instance(AutoCrawlConferencesUseCase::class, $useCase);

    // When / Then: 期待する stdout が出る + 終了コード 0
    $this->artisan('conferences:auto-crawl')
        ->expectsOutputToContain('巡回件数: 3')
        ->expectsOutputToContain('差分検知: 1')
        ->expectsOutputToContain('抽出失敗: 0')
        ->assertExitCode(0);
});

it('artisan conferences:auto-crawl は extractionFailed > 0 で失敗 URL を警告として表示', function () {
    // Given
    $result = new AutoCrawlResult(
        totalChecked: 3,
        diffDetected: 0,
        extractionFailed: 2,
        failedUrls: ['https://a.example.com/', 'https://b.example.com/'],
    );
    $useCase = Mockery::mock(AutoCrawlConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn($result);
    app()->instance(AutoCrawlConferencesUseCase::class, $useCase);

    // When / Then: 失敗 URL が出力される
    $this->artisan('conferences:auto-crawl')
        ->expectsOutputToContain('抽出失敗 URL:')
        ->expectsOutputToContain('https://a.example.com/')
        ->expectsOutputToContain('https://b.example.com/')
        ->assertExitCode(0);
});
