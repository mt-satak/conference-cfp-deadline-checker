<?php

declare(strict_types=1);

use App\Domain\Build\BuildServiceNotConfiguredException;
use App\Domain\GitHubApp\GitHubAppCredentials;
use App\Domain\GitHubApp\InstallationToken;
use App\Infrastructure\GitHubApp\GitHubActionsBuildTriggerer;
use App\Infrastructure\GitHubApp\GitHubAppClient;

/**
 * GitHubActionsBuildTriggerer の単体テスト (Phase 5.3 / Issue #110)。
 *
 * GitHubAppClient を Mockery で mock し、
 *  - 設定欠けで BuildServiceNotConfiguredException が投げられる
 *  - 設定揃っていれば installation token 取得 → workflow_dispatch が順番通り呼ばれる
 *  - 戻り値が ISO 8601 形式の受付時刻になる
 * を検証する。
 */
function makeFakeToken(): InstallationToken
{
    return new InstallationToken(
        token: 'ghs_test_token',
        expiresAt: new DateTimeImmutable('2026-05-07T13:00:00+09:00'),
    );
}

describe('GitHubActionsBuildTriggerer', function () {
    it('appId が空なら BuildServiceNotConfiguredException::appIdMissing を投げる', function () {
        // Given
        $client = Mockery::mock(GitHubAppClient::class);
        $triggerer = new GitHubActionsBuildTriggerer(
            client: $client,
            appId: '',
            installationId: '789012',
            privateKey: 'key',
            owner: 'mt-satak',
            repo: 'conference-cfp-deadline-checker',
            workflowFileName: 'deploy.yml',
            ref: 'main',
        );

        // When / Then
        expect(fn () => $triggerer->trigger())
            ->toThrow(BuildServiceNotConfiguredException::class, 'app ID');
    });

    it('installationId が空なら installationIdMissing を投げる', function () {
        // Given
        $client = Mockery::mock(GitHubAppClient::class);
        $triggerer = new GitHubActionsBuildTriggerer(
            client: $client,
            appId: '123456',
            installationId: '',
            privateKey: 'key',
            owner: 'mt-satak',
            repo: 'conference-cfp-deadline-checker',
            workflowFileName: 'deploy.yml',
            ref: 'main',
        );

        // When / Then
        expect(fn () => $triggerer->trigger())
            ->toThrow(BuildServiceNotConfiguredException::class, 'installation');
    });

    it('privateKey が空なら privateKeyMissing を投げる', function () {
        // Given
        $client = Mockery::mock(GitHubAppClient::class);
        $triggerer = new GitHubActionsBuildTriggerer(
            client: $client,
            appId: '123456',
            installationId: '789012',
            privateKey: '',
            owner: 'mt-satak',
            repo: 'conference-cfp-deadline-checker',
            workflowFileName: 'deploy.yml',
            ref: 'main',
        );

        // When / Then
        expect(fn () => $triggerer->trigger())
            ->toThrow(BuildServiceNotConfiguredException::class, 'private key');
    });

    it('設定が揃っていれば installation token 取得 → workflow_dispatch を順番に呼び ISO 8601 を返す', function () {
        // Given
        $client = Mockery::mock(GitHubAppClient::class);
        $client->shouldReceive('getInstallationToken')
            ->once()
            ->withArgs(function (GitHubAppCredentials $creds) {
                return $creds->appId === '123456'
                    && $creds->installationId === '789012'
                    && $creds->privateKey === 'pem-key';
            })
            ->andReturn(makeFakeToken());
        $client->shouldReceive('dispatchWorkflow')
            ->once()
            ->with(
                Mockery::type(InstallationToken::class),
                'mt-satak',
                'conference-cfp-deadline-checker',
                'deploy.yml',
                'main',
            );

        $triggerer = new GitHubActionsBuildTriggerer(
            client: $client,
            appId: '123456',
            installationId: '789012',
            privateKey: 'pem-key',
            owner: 'mt-satak',
            repo: 'conference-cfp-deadline-checker',
            workflowFileName: 'deploy.yml',
            ref: 'main',
        );

        // When
        $requestedAt = $triggerer->trigger();

        // Then: ISO 8601 (`YYYY-MM-DDTHH:MM:SS+09:00` 等) であること
        expect($requestedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
    });
});
