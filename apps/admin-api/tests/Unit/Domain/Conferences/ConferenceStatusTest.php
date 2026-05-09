<?php

declare(strict_types=1);

use App\Domain\Conferences\ConferenceStatus;

/**
 * ConferenceStatus enum のテスト (Issue #165 Phase 1)。
 *
 * Phase 0.5 (Issue #41) で導入時は Draft / Published の 2 値だったが、
 * Issue #165 で「開催日を過ぎた Published を自動アーカイブ」する機能のために
 * Archived (3 番目の値) を追加した。
 *
 * Archived の意味:
 * - 物理削除ではなくソフト削除 (= DB 上は残るが、admin UI のデフォルトタブから除外)
 * - 開催が完全に終わった Conference をノイズとして扱わないために存在する
 * - 必要時に「アーカイブ」タブから閲覧可能 (Phase 1 では閲覧のみ、Phase 2 以降で
 *   manual unarchive UI 検討)
 */
describe('ConferenceStatus enum', function () {
    it('Draft / Published / Archived の 3 値を持つ', function () {
        // Given/When
        $values = array_column(ConferenceStatus::cases(), 'value');

        // Then
        expect($values)->toBe(['draft', 'published', 'archived']);
    });

    it('Archived は string で archived として表現される', function () {
        // Given/When/Then: tryFrom('archived') で取得可能
        expect(ConferenceStatus::tryFrom('archived'))->toBe(ConferenceStatus::Archived);
    });
});
