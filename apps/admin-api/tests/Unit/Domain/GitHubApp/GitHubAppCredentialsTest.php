<?php

declare(strict_types=1);

use App\Domain\GitHubApp\GitHubAppCredentials;

/**
 * GitHubAppCredentials の値オブジェクトテスト (Phase 5.3 / Issue #110)。
 *
 * GitHub App 認証に必要な (appId, installationId, privateKey) を保持する。
 * 値の中身が空文字だと外部 API 認証ができないため Domain 段階で弾く。
 */
describe('GitHubAppCredentials', function () {
    it('appId / installationId / privateKey が揃っていれば生成できる', function () {
        // Given
        $appId = '123456';
        $installationId = '789012';
        $privateKey = "-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKCAQEA...\n-----END RSA PRIVATE KEY-----";

        // When
        $credentials = new GitHubAppCredentials($appId, $installationId, $privateKey);

        // Then
        expect($credentials->appId)->toBe($appId);
        expect($credentials->installationId)->toBe($installationId);
        expect($credentials->privateKey)->toBe($privateKey);
    });

    it('appId が空文字なら InvalidArgumentException を投げる', function () {
        // Given
        $emptyAppId = '';

        // When / Then
        expect(fn () => new GitHubAppCredentials(
            $emptyAppId,
            '789012',
            'key',
        ))->toThrow(InvalidArgumentException::class);
    });

    it('installationId が空文字なら InvalidArgumentException を投げる', function () {
        // Given
        $emptyInstallationId = '';

        // When / Then
        expect(fn () => new GitHubAppCredentials(
            '123456',
            $emptyInstallationId,
            'key',
        ))->toThrow(InvalidArgumentException::class);
    });

    it('privateKey が空文字なら InvalidArgumentException を投げる', function () {
        // Given
        $emptyPrivateKey = '';

        // When / Then
        expect(fn () => new GitHubAppCredentials(
            '123456',
            '789012',
            $emptyPrivateKey,
        ))->toThrow(InvalidArgumentException::class);
    });
});
