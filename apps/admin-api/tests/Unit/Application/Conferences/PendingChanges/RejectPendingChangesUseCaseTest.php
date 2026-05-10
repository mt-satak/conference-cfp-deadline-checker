<?php

declare(strict_types=1);

use App\Application\Conferences\PendingChanges\RejectPendingChangesUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceNotFoundException;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceStatus;

/**
 * RejectPendingChangesUseCase の単体テスト (Issue #188 PR-3)。
 *
 * 動作:
 * - findById で Conference を取得 (なければ ConferenceNotFoundException)
 * - actual フィールドは一切変更せず、pendingChanges のみ null にクリア
 * - updatedAt を現在時刻 (JST) に更新
 * - Repository->save() で永続化
 *
 * 例外仕様:
 * - 該当 Conference が無い: ConferenceNotFoundException
 * - pendingChanges が null / 空配列: no-op で現状を返す (= 二重クリック safe)
 */
function makeConferenceForReject(string $id, ?array $pendingChanges = null): Conference
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

describe('RejectPendingChangesUseCase', function () {
    it('pendingChanges のみを null にクリアし actual は一切変更しない', function () {
        // Given
        $existing = makeConferenceForReject('c1', [
            'cfpEndDate' => ['old' => '2026-07-15', 'new' => '2026-08-01'],
            'venue' => ['old' => '東京', 'new' => 'オンライン'],
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

        $useCase = new RejectPendingChangesUseCase($repo);

        // When
        $result = $useCase->execute('c1');

        // Then: actual 不変、pendingChanges のみクリア
        /** @var Conference $saved */
        expect($saved->cfpEndDate)->toBe('2026-07-15');  // 元のまま
        expect($saved->venue)->toBe('東京');  // 元のまま
        expect($saved->pendingChanges)->toBeNull();
        expect($result->pendingChanges)->toBeNull();
    });

    it('updatedAt は現在時刻 (JST) に更新される', function () {
        // Given
        $existing = makeConferenceForReject('c1', [
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

        $useCase = new RejectPendingChangesUseCase($repo);

        // When
        $useCase->execute('c1');

        // Then
        /** @var Conference $saved */
        expect($saved->updatedAt)->not->toBe($originalUpdatedAt);
    });

    it('Conference が存在しない場合は ConferenceNotFoundException を投げる', function () {
        // Given
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findById')->once()->with('missing')->andReturn(null);
        $repo->shouldNotReceive('save');

        $useCase = new RejectPendingChangesUseCase($repo);

        // When/Then
        $useCase->execute('missing');
    })->throws(ConferenceNotFoundException::class);

    it('pendingChanges が null の場合は no-op (save 呼ばず現状を返す = 二重クリック safe)', function () {
        // Given
        $existing = makeConferenceForReject('c1', null);
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findById')->once()->andReturn($existing);
        $repo->shouldNotReceive('save');

        $useCase = new RejectPendingChangesUseCase($repo);

        // When
        $result = $useCase->execute('c1');

        // Then
        expect($result)->toBe($existing);
    });

    it('pendingChanges が空配列の場合も no-op', function () {
        // Given
        $existing = makeConferenceForReject('c1', []);
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findById')->once()->andReturn($existing);
        $repo->shouldNotReceive('save');

        $useCase = new RejectPendingChangesUseCase($repo);

        // When
        $result = $useCase->execute('c1');

        // Then
        expect($result)->toBe($existing);
    });
});
