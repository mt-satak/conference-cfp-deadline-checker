<?php

declare(strict_types=1);

use App\Application\Conferences\BulkDelete\BulkDeleteConferencesResult;
use App\Application\Conferences\BulkDelete\BulkDeleteConferencesUseCase;
use App\Domain\Conferences\ConferenceRepository;

/**
 * BulkDeleteConferencesUseCase の単体テスト (Issue #219)。
 *
 * 一覧画面でチェックした複数行を一括削除する。
 *
 * 設計判断: fail-soft。
 *   単件の DeleteConferenceUseCase は not-found で ConferenceNotFoundException を
 *   投げるが、bulk では別タブで既に削除済みの行が混ざっても全体を止めず、
 *   deleteById が false を返した分はスキップして deletedCount に数えない。
 *   (= 並行削除で一部が消えていても残りは確実に消す UX)
 */
describe('BulkDeleteConferencesUseCase', function () {
    it('指定 ID をすべて削除し、件数を返す', function () {
        // Given: 3 件すべて削除成功
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('deleteById')->once()->with('id-1')->andReturn(true);
        $repo->shouldReceive('deleteById')->once()->with('id-2')->andReturn(true);
        $repo->shouldReceive('deleteById')->once()->with('id-3')->andReturn(true);
        $useCase = new BulkDeleteConferencesUseCase($repo);

        // When
        $result = $useCase->execute(['id-1', 'id-2', 'id-3']);

        // Then
        expect($result)->toBeInstanceOf(BulkDeleteConferencesResult::class);
        expect($result->requestedCount)->toBe(3);
        expect($result->deletedCount)->toBe(3);
    });

    it('既に削除済みの ID (deleteById=false) はスキップして deletedCount に数えない (fail-soft)', function () {
        // Given: id-2 は既に存在しない
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('deleteById')->once()->with('id-1')->andReturn(true);
        $repo->shouldReceive('deleteById')->once()->with('id-2')->andReturn(false);
        $repo->shouldReceive('deleteById')->once()->with('id-3')->andReturn(true);
        $useCase = new BulkDeleteConferencesUseCase($repo);

        // When
        $result = $useCase->execute(['id-1', 'id-2', 'id-3']);

        // Then: 3 件要求のうち 2 件削除
        expect($result->requestedCount)->toBe(3);
        expect($result->deletedCount)->toBe(2);
    });

    it('重複した ID は 1 回だけ削除する (= 二重カウント防止)', function () {
        // Given: id-1 が 2 回渡される
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('deleteById')->once()->with('id-1')->andReturn(true);
        $useCase = new BulkDeleteConferencesUseCase($repo);

        // When
        $result = $useCase->execute(['id-1', 'id-1']);

        // Then: 重複排除して 1 件
        expect($result->requestedCount)->toBe(1);
        expect($result->deletedCount)->toBe(1);
    });

    it('空配列なら何も削除せず deletedCount=0 を返す', function () {
        // Given: deleteById は一切呼ばれない
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldNotReceive('deleteById');
        $useCase = new BulkDeleteConferencesUseCase($repo);

        // When
        $result = $useCase->execute([]);

        // Then
        expect($result->requestedCount)->toBe(0);
        expect($result->deletedCount)->toBe(0);
    });
});
