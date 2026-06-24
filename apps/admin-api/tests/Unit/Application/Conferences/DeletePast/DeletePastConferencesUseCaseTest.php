<?php

declare(strict_types=1);

use App\Application\Conferences\DeletePast\DeletePastConferencesResult;
use App\Application\Conferences\DeletePast\DeletePastConferencesUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceStatus;

/**
 * DeletePastConferencesUseCase のユニットテスト (Issue #221 PR-1)。
 *
 * 責務:
 * - Repository から全件取得し、開催日が過去 (isPastEvent) の Conference を
 *   全ステータス対象でハード削除する
 * - 削除対象が無ければ deleteById を一切呼ばずスキップ (= 翌週再チェック)
 * - dry-run mode では deleteById を呼ばず対象一覧のみ返す
 *
 * 過去判定 (eventEndDate / eventStartDate と today の比較) の詳細は
 * Conference::isPastEvent のドメインテストでカバーするため、本テストは
 * 「どの Conference が削除対象になるか」「dry-run で削除しない」等の
 * UseCase 固有の振る舞いのみ検証する。
 */
function makeDeletePastConference(
    string $id,
    ConferenceStatus $status,
    ?string $eventEndDate,
    ?string $eventStartDate = null,
): Conference {
    return new Conference(
        conferenceId: $id,
        name: "Conf {$id}",
        trackName: null,
        officialUrl: 'https://example.com',
        cfpUrl: null,
        eventStartDate: $eventStartDate,
        eventEndDate: $eventEndDate,
        venue: null,
        format: ConferenceFormat::Offline,
        cfpStartDate: null,
        cfpEndDate: null,
        categories: [],
        description: null,
        themeColor: null,
        createdAt: '2026-04-01T10:00:00+09:00',
        updatedAt: '2026-04-01T10:00:00+09:00',
        status: $status,
    );
}

describe('DeletePastConferencesUseCase', function () {
    it('開催日が過去の行を全ステータス対象で削除する', function () {
        // Given: 過去 Draft / 過去 Published (2 件) / 未来 Published
        // (ステータスを問わず過去なら削除されることを Draft + Published で確認)
        $pastDraft = makeDeletePastConference('past-draft', ConferenceStatus::Draft, '2026-06-01');
        $pastPub1 = makeDeletePastConference('past-pub1', ConferenceStatus::Published, '2026-06-10');
        $pastPub2 = makeDeletePastConference('past-pub2', ConferenceStatus::Published, '2026-05-01');
        $future = makeDeletePastConference('future', ConferenceStatus::Published, '2026-12-01');

        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$pastDraft, $pastPub1, $pastPub2, $future]);
        // 過去の 3 件すべてが削除される (ステータス不問)、未来は呼ばれない
        $repo->shouldReceive('deleteById')->once()->with('past-draft')->andReturn(true);
        $repo->shouldReceive('deleteById')->once()->with('past-pub1')->andReturn(true);
        $repo->shouldReceive('deleteById')->once()->with('past-pub2')->andReturn(true);

        $useCase = new DeletePastConferencesUseCase($repo);

        // When: today = 2026-06-24
        $result = $useCase->execute('2026-06-24');

        // Then
        expect($result)->toBeInstanceOf(DeletePastConferencesResult::class);
        expect($result->totalChecked)->toBe(4);
        expect($result->deletedCount)->toBe(3);
        expect($result->deletedIds)->toBe(['past-draft', 'past-pub1', 'past-pub2']);
        expect($result->dryRun)->toBeFalse();
    });

    it('削除対象が無ければ deleteById を呼ばずスキップする', function () {
        // Given: すべて未来 or 日付なし
        $future = makeDeletePastConference('future', ConferenceStatus::Published, '2026-12-01');
        $noDate = makeDeletePastConference('no-date', ConferenceStatus::Draft, null, null);

        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$future, $noDate]);
        $repo->shouldNotReceive('deleteById');

        $useCase = new DeletePastConferencesUseCase($repo);

        // When
        $result = $useCase->execute('2026-06-24');

        // Then: 0 件スキップ
        expect($result->totalChecked)->toBe(2);
        expect($result->deletedCount)->toBe(0);
        expect($result->deletedIds)->toBe([]);
    });

    it('dry-run では deleteById を呼ばず対象一覧のみ返す', function () {
        // Given: 過去 1 件
        $past = makeDeletePastConference('past', ConferenceStatus::Published, '2026-06-01');

        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$past]);
        $repo->shouldNotReceive('deleteById');

        $useCase = new DeletePastConferencesUseCase($repo);

        // When
        $result = $useCase->execute('2026-06-24', dryRun: true);

        // Then: 対象は挙がるが削除はしない
        expect($result->dryRun)->toBeTrue();
        expect($result->deletedCount)->toBe(1);
        expect($result->deletedIds)->toBe(['past']);
    });

    it('eventEndDate が無い場合は eventStartDate で過去判定する', function () {
        // Given: eventEndDate=null, eventStartDate=過去
        $past = makeDeletePastConference('start-only', ConferenceStatus::Published, null, '2026-06-01');

        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$past]);
        $repo->shouldReceive('deleteById')->once()->with('start-only')->andReturn(true);

        $useCase = new DeletePastConferencesUseCase($repo);

        // When
        $result = $useCase->execute('2026-06-24');

        // Then
        expect($result->deletedCount)->toBe(1);
    });

    it('deleteById が false (既に削除済み) を返した分は deletedCount に数えない (fail-soft)', function () {
        // Given: 過去 2 件、1 件は既に消えている
        $p1 = makeDeletePastConference('p1', ConferenceStatus::Published, '2026-06-01');
        $p2 = makeDeletePastConference('p2', ConferenceStatus::Published, '2026-06-02');

        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$p1, $p2]);
        $repo->shouldReceive('deleteById')->once()->with('p1')->andReturn(true);
        $repo->shouldReceive('deleteById')->once()->with('p2')->andReturn(false);

        $useCase = new DeletePastConferencesUseCase($repo);

        // When
        $result = $useCase->execute('2026-06-24');

        // Then: deletedIds は実削除できた p1 のみ
        expect($result->deletedCount)->toBe(1);
        expect($result->deletedIds)->toBe(['p1']);
    });
});
