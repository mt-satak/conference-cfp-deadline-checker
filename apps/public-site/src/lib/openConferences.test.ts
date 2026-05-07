import { describe, it, expect } from 'vitest';
import type { Conference } from '../types/conference';
import { filterOpenConferences } from './openConferences';

/**
 * filterOpenConferences の純粋関数テスト (Issue #86 / Phase 3)。
 *
 * 「あと N 日」がアクション可能な open / urgent ステータスだけを残す共通フィルタ。
 * トップページ / カテゴリ別ページの両方で使う。
 */

function makeConference(id: string, cfpEndDate: string | null): Conference {
    return {
        id,
        name: `Conference ${id}`,
        officialUrl: 'https://example.com/',
        eventStartDate: null,
        eventEndDate: null,
        venue: null,
        format: null,
        cfpEndDate,
        description: null,
        categories: [],
    };
}

describe('filterOpenConferences', () => {
    const today = new Date('2026-05-07T00:00:00Z');

    it('open / urgent / today ステータスのカンファレンスは残し、closed / unknown は除外する', () => {
        // Given:
        const open = makeConference('open', '2026-06-30'); // 54 日後 = open
        const urgent = makeConference('urgent', '2026-05-10'); // 3 日後 = urgent
        const today_deadline = makeConference('today', '2026-05-07'); // 当日 = today
        const closed = makeConference('closed', '2026-05-01'); // 過去 = closed
        const unknown = makeConference('unknown', null); // 締切未定 = unknown

        // When
        const result = filterOpenConferences(
            [open, urgent, today_deadline, closed, unknown],
            today,
        );

        // Then
        expect(result).toEqual([open, urgent, today_deadline]);
    });

    it('全件 closed / unknown の場合は空配列を返す', () => {
        // Given
        const closed = makeConference('1', '2026-04-01');
        const unknown = makeConference('2', null);

        // When
        const result = filterOpenConferences([closed, unknown], today);

        // Then
        expect(result).toEqual([]);
    });

    it('元の配列を変更しない (純粋関数)', () => {
        // Given
        const original = [
            makeConference('a', '2026-06-30'),
            makeConference('b', '2026-04-01'),
        ];
        const snapshot = [...original];

        // When
        filterOpenConferences(original, today);

        // Then
        expect(original).toEqual(snapshot);
    });
});
