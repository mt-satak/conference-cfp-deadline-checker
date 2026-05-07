<?php

declare(strict_types=1);

use App\Domain\GitHubApp\InstallationToken;

/**
 * InstallationToken の値オブジェクトテスト (Phase 5.3 / Issue #110)。
 *
 * GitHub App の installation access token (= 1 時間有効) と失効時刻のペア。
 * 失効判定を Domain 段階で表現することで、Infrastructure 層がキャッシュ判断
 * (再取得 vs 流用) を機械的に行えるようにする。
 */
describe('InstallationToken', function () {
    it('token と expiresAt を保持する', function () {
        // Given
        $token = 'ghs_xxxxxxxxxxxxxxxx';
        $expiresAt = new DateTimeImmutable('2026-05-07T12:00:00+09:00');

        // When
        $installationToken = new InstallationToken($token, $expiresAt);

        // Then
        expect($installationToken->token)->toBe($token);
        expect($installationToken->expiresAt)->toBe($expiresAt);
    });

    it('token が空文字なら InvalidArgumentException を投げる', function () {
        // Given
        $emptyToken = '';
        $expiresAt = new DateTimeImmutable('2026-05-07T12:00:00+09:00');

        // When / Then
        expect(fn () => new InstallationToken($emptyToken, $expiresAt))
            ->toThrow(InvalidArgumentException::class);
    });

    it('現在時刻が expiresAt より前なら isExpired は false', function () {
        // Given: 失効 1 時間前
        $expiresAt = new DateTimeImmutable('2026-05-07T13:00:00+09:00');
        $token = new InstallationToken('ghs_x', $expiresAt);
        $now = new DateTimeImmutable('2026-05-07T12:00:00+09:00');

        // When
        $expired = $token->isExpired($now);

        // Then
        expect($expired)->toBeFalse();
    });

    it('現在時刻が expiresAt と同時刻なら isExpired は true (= 失効扱い)', function () {
        // Given: ちょうど失効時刻 (= 安全サイドに倒して expired)
        $expiresAt = new DateTimeImmutable('2026-05-07T12:00:00+09:00');
        $token = new InstallationToken('ghs_x', $expiresAt);
        $now = new DateTimeImmutable('2026-05-07T12:00:00+09:00');

        // When
        $expired = $token->isExpired($now);

        // Then
        expect($expired)->toBeTrue();
    });

    it('現在時刻が expiresAt より後なら isExpired は true', function () {
        // Given: 失効 1 時間後
        $expiresAt = new DateTimeImmutable('2026-05-07T11:00:00+09:00');
        $token = new InstallationToken('ghs_x', $expiresAt);
        $now = new DateTimeImmutable('2026-05-07T12:00:00+09:00');

        // When
        $expired = $token->isExpired($now);

        // Then
        expect($expired)->toBeTrue();
    });
});
