<?php

declare(strict_types=1);

use App\Domain\Conferences\ConferenceStatus;

/**
 * ConferenceStatus enum のテスト。
 *
 * Phase 0.5 (Issue #41) で Draft / Published を導入。
 * Issue #221 で旧 Archived 状態を廃止し、再び Draft / Published の 2 値に戻した
 * (= 過去イベントは DeletePastTask が週次でハード削除する方針)。
 */
describe('ConferenceStatus enum', function () {
    it('Draft / Published の 2 値を持つ', function () {
        // Given/When
        $values = array_column(ConferenceStatus::cases(), 'value');

        // Then
        expect($values)->toBe(['draft', 'published']);
    });

    it('廃止した archived 値は tryFrom で null を返す (Issue #221)', function () {
        // Given/When/Then: enum case を削除したため未知値扱い
        expect(ConferenceStatus::tryFrom('archived'))->toBeNull();
    });
});
