<?php

declare(strict_types=1);

use App\Domain\Build\BuildJobStatus;
use App\Domain\Build\BuildServiceNotConfiguredException;
use App\Domain\Build\BuildTriggerSource;
use App\Domain\GitHubApp\InstallationToken;
use App\Infrastructure\GitHubApp\GitHubActionsBuildStatusReader;
use App\Infrastructure\GitHubApp\GitHubAppClient;

/**
 * GitHubActionsBuildStatusReader の単体テスト (Phase 5.3 / Issue #110)。
 *
 * GitHub Actions workflow runs API のレスポンスから Domain BuildStatus への
 * マッピングを検証する。GitHub API の status / conclusion / event は以下の通り
 * Domain enum にマップする:
 *  - status=queued                  → BuildJobStatus::Pending
 *  - status=in_progress             → BuildJobStatus::Running
 *  - status=completed/success       → BuildJobStatus::Succeed
 *  - status=completed/failure       → BuildJobStatus::Failed
 *  - status=completed/timed_out     → BuildJobStatus::Failed
 *  - status=completed/cancelled     → BuildJobStatus::Cancelled
 *  - status=completed/その他         → BuildJobStatus::Failed (skipped/neutral 等を要注意化)
 *  - event=workflow_dispatch         → BuildTriggerSource::AdminManual
 *  - event=push                      → BuildTriggerSource::RepositoryPush
 *  - event=schedule                  → BuildTriggerSource::Scheduled
 *  - event=その他                    → null
 */
function fakeAccessToken(): InstallationToken
{
    return new InstallationToken(
        token: 'ghs_x',
        expiresAt: new DateTimeImmutable('2026-05-07T13:00:00+09:00'),
    );
}

describe('GitHubActionsBuildStatusReader', function () {
    it('appId が空なら BuildServiceNotConfiguredException::appIdMissing を投げる', function () {
        // Given
        $client = Mockery::mock(GitHubAppClient::class);
        $reader = new GitHubActionsBuildStatusReader(
            client: $client,
            appId: '',
            installationId: '789012',
            privateKey: 'key',
            owner: 'mt-satak',
            repo: 'conference-cfp-deadline-checker',
            workflowFileName: 'deploy.yml',
        );

        // When / Then
        expect(fn () => $reader->listRecent(10))
            ->toThrow(BuildServiceNotConfiguredException::class, 'app ID');
    });

    it('listWorkflowRuns 結果を BuildStatus 配列にマップする (status / conclusion / event を Domain enum に変換)', function () {
        // Given: GitHub API 応答 (3 件: success / in_progress / cancelled)
        $client = Mockery::mock(GitHubAppClient::class);
        $client->shouldReceive('getInstallationToken')
            ->once()
            ->andReturn(fakeAccessToken());
        $client->shouldReceive('listWorkflowRuns')
            ->once()
            ->with(
                Mockery::type(InstallationToken::class),
                'mt-satak',
                'conference-cfp-deadline-checker',
                'deploy.yml',
                10,
            )
            ->andReturn([
                [
                    'id' => 25475114607,
                    'status' => 'completed',
                    'conclusion' => 'success',
                    'created_at' => '2026-05-07T03:52:25Z',
                    'updated_at' => '2026-05-07T03:58:47Z',
                    'head_sha' => 'abc123',
                    'head_commit' => ['message' => 'fix(deploy): mock fallback 解消'],
                    'event' => 'workflow_dispatch',
                ],
                [
                    'id' => 25474718101,
                    'status' => 'in_progress',
                    'conclusion' => null,
                    'created_at' => '2026-05-07T03:38:11Z',
                    'updated_at' => '2026-05-07T03:38:11Z',
                    'head_sha' => 'def456',
                    'head_commit' => ['message' => 'feat: foo'],
                    'event' => 'push',
                ],
                [
                    'id' => 25473578507,
                    'status' => 'completed',
                    'conclusion' => 'cancelled',
                    'created_at' => '2026-05-07T02:59:45Z',
                    'updated_at' => '2026-05-07T03:00:12Z',
                    'head_sha' => 'ghi789',
                    'head_commit' => ['message' => 'test'],
                    'event' => 'schedule',
                ],
            ]);

        $reader = new GitHubActionsBuildStatusReader(
            client: $client,
            appId: '123456',
            installationId: '789012',
            privateKey: 'key',
            owner: 'mt-satak',
            repo: 'conference-cfp-deadline-checker',
            workflowFileName: 'deploy.yml',
        );

        // When
        $statuses = $reader->listRecent(10);

        // Then
        expect($statuses)->toHaveCount(3);

        // 1 件目: success / workflow_dispatch
        expect($statuses[0]->jobId)->toBe('25475114607');
        expect($statuses[0]->status)->toBe(BuildJobStatus::Succeed);
        expect($statuses[0]->startedAt)->toBe('2026-05-07T03:52:25Z');
        expect($statuses[0]->endedAt)->toBe('2026-05-07T03:58:47Z');
        expect($statuses[0]->commitId)->toBe('abc123');
        expect($statuses[0]->commitMessage)->toBe('fix(deploy): mock fallback 解消');
        expect($statuses[0]->triggerSource)->toBe(BuildTriggerSource::AdminManual);

        // 2 件目: in_progress / push (= 完了前なので endedAt は null)
        expect($statuses[1]->status)->toBe(BuildJobStatus::Running);
        expect($statuses[1]->endedAt)->toBeNull();
        expect($statuses[1]->triggerSource)->toBe(BuildTriggerSource::RepositoryPush);

        // 3 件目: cancelled / schedule
        expect($statuses[2]->status)->toBe(BuildJobStatus::Cancelled);
        expect($statuses[2]->triggerSource)->toBe(BuildTriggerSource::Scheduled);
    });

    it('未知の event は triggerSource null として扱う', function () {
        // Given
        $client = Mockery::mock(GitHubAppClient::class);
        $client->shouldReceive('getInstallationToken')->andReturn(fakeAccessToken());
        $client->shouldReceive('listWorkflowRuns')->andReturn([
            [
                'id' => 1,
                'status' => 'completed',
                'conclusion' => 'success',
                'created_at' => '2026-05-07T00:00:00Z',
                'updated_at' => '2026-05-07T00:00:30Z',
                'head_sha' => 'x',
                'head_commit' => ['message' => 'm'],
                'event' => 'pull_request',
            ],
        ]);

        $reader = new GitHubActionsBuildStatusReader(
            client: $client,
            appId: '123456',
            installationId: '789012',
            privateKey: 'key',
            owner: 'mt-satak',
            repo: 'conference-cfp-deadline-checker',
            workflowFileName: 'deploy.yml',
        );

        // When
        $statuses = $reader->listRecent(10);

        // Then
        expect($statuses[0]->triggerSource)->toBeNull();
    });

    it('未知の conclusion (skipped/neutral 等) は Failed 扱いにする', function () {
        // Given
        $client = Mockery::mock(GitHubAppClient::class);
        $client->shouldReceive('getInstallationToken')->andReturn(fakeAccessToken());
        $client->shouldReceive('listWorkflowRuns')->andReturn([
            [
                'id' => 1,
                'status' => 'completed',
                'conclusion' => 'skipped',
                'created_at' => '2026-05-07T00:00:00Z',
                'updated_at' => '2026-05-07T00:00:30Z',
                'head_sha' => 'x',
                'head_commit' => ['message' => 'm'],
                'event' => 'workflow_dispatch',
            ],
        ]);

        $reader = new GitHubActionsBuildStatusReader(
            client: $client,
            appId: '123456',
            installationId: '789012',
            privateKey: 'key',
            owner: 'mt-satak',
            repo: 'conference-cfp-deadline-checker',
            workflowFileName: 'deploy.yml',
        );

        // When
        $statuses = $reader->listRecent(10);

        // Then
        expect($statuses[0]->status)->toBe(BuildJobStatus::Failed);
    });

    it('空応答なら空配列を返す', function () {
        // Given
        $client = Mockery::mock(GitHubAppClient::class);
        $client->shouldReceive('getInstallationToken')->andReturn(fakeAccessToken());
        $client->shouldReceive('listWorkflowRuns')->andReturn([]);

        $reader = new GitHubActionsBuildStatusReader(
            client: $client,
            appId: '123456',
            installationId: '789012',
            privateKey: 'key',
            owner: 'mt-satak',
            repo: 'conference-cfp-deadline-checker',
            workflowFileName: 'deploy.yml',
        );

        // When
        $statuses = $reader->listRecent(10);

        // Then
        expect($statuses)->toBe([]);
    });
});
