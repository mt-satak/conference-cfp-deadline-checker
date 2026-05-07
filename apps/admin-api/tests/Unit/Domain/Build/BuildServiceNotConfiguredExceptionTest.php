<?php

declare(strict_types=1);

use App\Domain\Build\BuildServiceNotConfiguredException;

/**
 * BuildServiceNotConfiguredException の static factory テスト (Phase 5.3)。
 *
 * Phase 5.3 (Issue #110) で AWS Amplify から GitHub Actions 経路に切り替えた際、
 * factory メソッドを GitHub App 用 3 種 (appIdMissing / installationIdMissing /
 * privateKeyMissing) に揃えた。
 *
 * これらの例外は HTTP 層 (AdminApiExceptionRenderer) で 503 SERVICE_UNAVAILABLE
 * に整形される運用なのでメッセージ内容で原因種別が識別できることを保証する。
 */
describe('BuildServiceNotConfiguredException', function () {
    it('既存の appIdMissing factory が動作する', function () {
        // When
        $e = BuildServiceNotConfiguredException::appIdMissing();

        // Then
        expect($e)->toBeInstanceOf(BuildServiceNotConfiguredException::class);
        expect($e->getMessage())->toContain('app ID');
    });

    it('installationIdMissing factory が GitHub App 用メッセージを返す', function () {
        // When
        $e = BuildServiceNotConfiguredException::installationIdMissing();

        // Then
        expect($e)->toBeInstanceOf(BuildServiceNotConfiguredException::class);
        expect($e->getMessage())->toContain('installation');
    });

    it('privateKeyMissing factory が GitHub App 用メッセージを返す', function () {
        // When
        $e = BuildServiceNotConfiguredException::privateKeyMissing();

        // Then
        expect($e)->toBeInstanceOf(BuildServiceNotConfiguredException::class);
        expect($e->getMessage())->toContain('private key');
    });
});
