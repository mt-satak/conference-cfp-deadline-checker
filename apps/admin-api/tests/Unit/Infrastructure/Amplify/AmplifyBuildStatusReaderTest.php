<?php

use App\Domain\Build\BuildJobStatus;
use App\Domain\Build\BuildServiceNotConfiguredException;
use App\Domain\Build\BuildStatus;
use App\Infrastructure\Amplify\AmplifyBuildStatusReader;
use Aws\Amplify\AmplifyClient;
use Aws\Result;

/**
 * AmplifyBuildStatusReader の単体テスト (Amplify Client モック使用)。
 */
it('appId が null なら BuildServiceNotConfiguredException', function () {
    // Given
    $client = Mockery::mock(AmplifyClient::class);
    $client->shouldNotReceive('listJobs');

    // When/Then
    $reader = new AmplifyBuildStatusReader($client, null, 'main');
    expect(fn () => $reader->listRecent(10))
        ->toThrow(BuildServiceNotConfiguredException::class);
});

it('listJobs API を呼んで Job summaries を BuildStatus[] に変換する', function () {
    // Given: 2 件返す Amplify モック
    $startedAt = new DateTimeImmutable('2026-05-04T10:00:00+00:00');
    $endedAt = new DateTimeImmutable('2026-05-04T10:02:00+00:00');
    $client = Mockery::mock(AmplifyClient::class);
    $client->shouldReceive('listJobs')
        ->once()
        ->with(Mockery::on(function ($args) {
            return $args['appId'] === 'app-123'
                && $args['branchName'] === 'main'
                && $args['maxResults'] === 10;
        }))
        ->andReturn(new Result([
            'jobSummaries' => [
                [
                    'jobId' => 'j-1',
                    'status' => 'SUCCEED',
                    'startTime' => $startedAt,
                    'endTime' => $endedAt,
                    'commitId' => 'abc123',
                    'commitMessage' => 'fix typo',
                ],
                [
                    'jobId' => 'j-2',
                    'status' => 'RUNNING',
                    'startTime' => $startedAt,
                    // endTime / commit 系欠落
                ],
            ],
        ]));

    // When
    $reader = new AmplifyBuildStatusReader($client, 'app-123', 'main');
    $statuses = $reader->listRecent(10);

    // Then
    expect($statuses)->toHaveCount(2);
    expect($statuses[0])->toBeInstanceOf(BuildStatus::class);
    expect($statuses[0]->jobId)->toBe('j-1');
    expect($statuses[0]->status)->toBe(BuildJobStatus::Succeed);
    expect($statuses[0]->commitId)->toBe('abc123');
    expect($statuses[0]->endedAt)->not->toBeNull();
    expect($statuses[1]->status)->toBe(BuildJobStatus::Running);
    expect($statuses[1]->commitId)->toBeNull();
    expect($statuses[1]->endedAt)->toBeNull();
});

it('jobSummaries が空でも空配列を返す', function () {
    // Given
    $client = Mockery::mock(AmplifyClient::class);
    $client->shouldReceive('listJobs')->once()->andReturn(new Result(['jobSummaries' => []]));

    // When
    $reader = new AmplifyBuildStatusReader($client, 'app-123', 'main');
    $result = $reader->listRecent(10);

    // Then
    expect($result)->toBe([]);
});

it('未知の status 文字列が来たら Pending 扱いで防御 (落ちない)', function () {
    // Given: 想定外の status 値
    $client = Mockery::mock(AmplifyClient::class);
    $client->shouldReceive('listJobs')->once()->andReturn(new Result([
        'jobSummaries' => [[
            'jobId' => 'j-x',
            'status' => 'UNKNOWN_STATUS',
            'startTime' => '2026-05-04T10:00:00+00:00',
        ]],
    ]));

    // When
    $reader = new AmplifyBuildStatusReader($client, 'app-123', 'main');
    $statuses = $reader->listRecent(10);

    // Then: Pending にフォールバック
    expect($statuses[0]->status)->toBe(BuildJobStatus::Pending);
});
