<?php

declare(strict_types=1);

use App\Application\Conferences\PendingChanges\ApplyPendingChangesUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceNotFoundException;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceStatus;

/**
 * ApplyPendingChangesUseCase の単体テスト (Issue #188 PR-3)。
 *
 * 動作:
 * - findById で Conference を取得 (なければ ConferenceNotFoundException)
 * - pendingChanges の各フィールドの new 値を actual フィールドに反映
 *   - format は enum 文字列なので ConferenceFormat に再変換
 *   - 不明フィールドは defensive に無視 (未来の AutoCrawl 拡張時の互換性)
 * - pendingChanges を null にクリア
 * - updatedAt を現在時刻 (JST) に更新
 * - Repository->save() で永続化
 *
 * 例外仕様:
 * - 該当 Conference が無い: ConferenceNotFoundException
 * - pendingChanges が null / 空配列: no-op で現状を返す (= 二重クリック safe)
 */
function makePublishedWithPending(string $id, ?array $pendingChanges = null): Conference
{
    return new Conference(
        conferenceId: $id,
        name: "Conf {$id}",
        trackName: null,
        officialUrl: 'https://x.example.com/',
        cfpUrl: 'https://x.example.com/cfp',
        eventStartDate: '2026-09-19',
        eventEndDate: '2026-09-20',
        venue: '東京',
        format: ConferenceFormat::Offline,
        cfpStartDate: null,
        cfpEndDate: '2026-07-15',
        categories: [],
        description: null,
        themeColor: null,
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
        status: ConferenceStatus::Published,
        pendingChanges: $pendingChanges,
    );
}

describe('ApplyPendingChangesUseCase', function () {
    it('cfpEndDate の保留差分を actual に反映し pendingChanges をクリアする', function () {
        // Given: cfpEndDate に保留差分 (2026-07-15 → 2026-08-01)
        $existing = makePublishedWithPending('c1', [
            'cfpEndDate' => ['old' => '2026-07-15', 'new' => '2026-08-01'],
        ]);
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findById')->once()->with('c1')->andReturn($existing);

        $saved = null;
        $repo->shouldReceive('save')
            ->once()
            ->with(Mockery::on(function (Conference $c) use (&$saved) {
                $saved = $c;

                return true;
            }));

        $useCase = new ApplyPendingChangesUseCase($repo);

        // When
        $result = $useCase->execute('c1');

        // Then: actual が new 値で更新され、pendingChanges は null
        /** @var Conference $saved */
        expect($saved->cfpEndDate)->toBe('2026-08-01');
        expect($saved->pendingChanges)->toBeNull();
        // 他フィールドは保持
        expect($saved->name)->toBe($existing->name);
        expect($saved->venue)->toBe($existing->venue);
        expect($saved->conferenceId)->toBe('c1');
        expect($result->cfpEndDate)->toBe('2026-08-01');
    });

    it('format の保留差分は enum 文字列を ConferenceFormat に再変換して反映する', function () {
        // Given: format が Offline → hybrid (= AutoCrawl で .value 文字列で保存されている)
        $existing = makePublishedWithPending('c1', [
            'format' => ['old' => 'offline', 'new' => 'hybrid'],
        ]);
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findById')->once()->andReturn($existing);

        $saved = null;
        $repo->shouldReceive('save')
            ->once()
            ->with(Mockery::on(function (Conference $c) use (&$saved) {
                $saved = $c;

                return true;
            }));

        $useCase = new ApplyPendingChangesUseCase($repo);

        // When
        $useCase->execute('c1');

        // Then: enum で actual に反映
        /** @var Conference $saved */
        expect($saved->format)->toBe(ConferenceFormat::Hybrid);
        expect($saved->pendingChanges)->toBeNull();
    });

    it('複数フィールドの保留差分を全て actual に反映する', function () {
        // Given: venue + cfpEndDate の保留差分
        $existing = makePublishedWithPending('c1', [
            'venue' => ['old' => '東京', 'new' => '東京 (千代田区)'],
            'cfpEndDate' => ['old' => '2026-07-15', 'new' => '2026-08-01'],
        ]);
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findById')->once()->andReturn($existing);

        $saved = null;
        $repo->shouldReceive('save')
            ->once()
            ->with(Mockery::on(function (Conference $c) use (&$saved) {
                $saved = $c;

                return true;
            }));

        $useCase = new ApplyPendingChangesUseCase($repo);

        // When
        $useCase->execute('c1');

        // Then
        /** @var Conference $saved */
        expect($saved->venue)->toBe('東京 (千代田区)');
        expect($saved->cfpEndDate)->toBe('2026-08-01');
        expect($saved->pendingChanges)->toBeNull();
    });

    it('updatedAt は現在時刻 (JST) に更新される', function () {
        // Given
        $existing = makePublishedWithPending('c1', [
            'venue' => ['old' => '東京', 'new' => '大阪'],
        ]);
        $originalUpdatedAt = $existing->updatedAt;
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findById')->once()->andReturn($existing);

        $saved = null;
        $repo->shouldReceive('save')
            ->once()
            ->with(Mockery::on(function (Conference $c) use (&$saved) {
                $saved = $c;

                return true;
            }));

        $useCase = new ApplyPendingChangesUseCase($repo);

        // When
        $useCase->execute('c1');

        // Then: updatedAt が前と異なる (= 何らかの新時刻)
        /** @var Conference $saved */
        expect($saved->updatedAt)->not->toBe($originalUpdatedAt);
    });

    it('Conference が存在しない場合は ConferenceNotFoundException を投げる', function () {
        // Given
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findById')->once()->with('missing')->andReturn(null);
        $repo->shouldNotReceive('save');

        $useCase = new ApplyPendingChangesUseCase($repo);

        // When/Then
        $useCase->execute('missing');
    })->throws(ConferenceNotFoundException::class);

    it('pendingChanges が null の場合は no-op (save 呼ばず現状を返す = 二重クリック safe)', function () {
        // Given: pendingChanges 無し
        $existing = makePublishedWithPending('c1', null);
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findById')->once()->andReturn($existing);
        $repo->shouldNotReceive('save');

        $useCase = new ApplyPendingChangesUseCase($repo);

        // When
        $result = $useCase->execute('c1');

        // Then: 現状をそのまま返す
        expect($result)->toBe($existing);
    });

    it('pendingChanges が空配列の場合も no-op', function () {
        // Given
        $existing = makePublishedWithPending('c1', []);
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findById')->once()->andReturn($existing);
        $repo->shouldNotReceive('save');

        $useCase = new ApplyPendingChangesUseCase($repo);

        // When
        $result = $useCase->execute('c1');

        // Then
        expect($result)->toBe($existing);
    });

    it('未知のフィールド名 (= 未来の AutoCrawl 拡張) は無視して既知フィールドのみ反映する', function () {
        // Given: 既知 (venue) + 未知 (mysteryField)
        $existing = makePublishedWithPending('c1', [
            'venue' => ['old' => '東京', 'new' => '大阪'],
            'mysteryField' => ['old' => 'a', 'new' => 'b'],
        ]);
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findById')->once()->andReturn($existing);

        $saved = null;
        $repo->shouldReceive('save')
            ->once()
            ->with(Mockery::on(function (Conference $c) use (&$saved) {
                $saved = $c;

                return true;
            }));

        $useCase = new ApplyPendingChangesUseCase($repo);

        // When: 未知フィールドが Conference コンストラクタに渡って例外にならない
        $useCase->execute('c1');

        // Then: venue だけ反映、mysteryField は無視 (= defensive)
        /** @var Conference $saved */
        expect($saved->venue)->toBe('大阪');
        expect($saved->pendingChanges)->toBeNull();
    });

    it('format の new 値が不正文字列の場合は actual を null にして反映 (= ConferenceFormat::tryFrom が null を返す)', function () {
        // Given: format に不正値 (= AutoCrawl が壊れた値を保存した想定、防御的に null セット)
        $existing = makePublishedWithPending('c1', [
            'format' => ['old' => 'offline', 'new' => 'invalid-format'],
        ]);
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findById')->once()->andReturn($existing);

        $saved = null;
        $repo->shouldReceive('save')
            ->once()
            ->with(Mockery::on(function (Conference $c) use (&$saved) {
                $saved = $c;

                return true;
            }));

        $useCase = new ApplyPendingChangesUseCase($repo);

        // When
        $useCase->execute('c1');

        // Then: format は null (= tryFrom が null を返した結果)、pendingChanges はクリア
        /** @var Conference $saved */
        expect($saved->format)->toBeNull();
        expect($saved->pendingChanges)->toBeNull();
    });
});
