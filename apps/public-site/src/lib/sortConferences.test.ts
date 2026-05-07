import { describe, it, expect } from 'vitest';
import type { Conference } from '../types/conference';
import { sortByDeadlineAsc } from './sortConferences';

/**
 * sortByDeadlineAsc の純粋関数テスト (Issue #86 / Phase 3)。
 *
 * トップページ / カテゴリ別ページで「締切が近い順」を明示的に表現するためのソート。
 */

function makeConference(id: string, name: string, cfpEndDate: string | null): Conference {
    return {
        id,
        name,
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

describe('sortByDeadlineAsc', () => {
    it('cfpEndDate の早い順 (= 締切が近い順) に並び替える', () => {
        // Given: 異なる締切のカンファレンス 3 件、入力順は不揃い
        const a = makeConference('a', 'A', '2026-09-01');
        const b = makeConference('b', 'B', '2026-05-15');
        const c = makeConference('c', 'C', '2026-07-10');

        // When
        const result = sortByDeadlineAsc([a, b, c]);

        // Then: B (5/15) → C (7/10) → A (9/1) の順
        expect(result.map((x) => x.id)).toEqual(['b', 'c', 'a']);
    });

    it('同日締切の場合は名前で安定的に並び替える', () => {
        // Given: 同日締切で名前が異なる 2 件
        const z = makeConference('z', 'Zoo Conf', '2026-05-20');
        const a = makeConference('a', 'Apple Conf', '2026-05-20');

        // When
        const result = sortByDeadlineAsc([z, a]);

        // Then: 名前昇順で a → z
        expect(result.map((x) => x.id)).toEqual(['a', 'z']);
    });

    it('元の配列を変更しない (純粋関数)', () => {
        // Given
        const original = [
            makeConference('1', '1', '2026-09-01'),
            makeConference('2', '2', '2026-05-01'),
        ];
        const snapshot = [...original];

        // When
        sortByDeadlineAsc(original);

        // Then
        expect(original).toEqual(snapshot);
    });

    it('cfpEndDate が null のカンファレンスは末尾にまとめる (= 不明扱い、表示優先度低)', () => {
        // Given: null と日付付きが混在
        const a = makeConference('a', 'A', '2026-05-15');
        const b = makeConference('b', 'B', null);
        const c = makeConference('c', 'C', '2026-07-10');

        // When
        const result = sortByDeadlineAsc([b, a, c]);

        // Then: 日付付きが昇順で先に並び、null は末尾
        expect(result.map((x) => x.id)).toEqual(['a', 'c', 'b']);
    });
});
