import { describe, it, expect } from 'vitest';
import type { Conference } from '../types/conference';
import { extractCategorySlugs, filterByCategory } from './categories';

/**
 * カテゴリ抽出 / フィルタの純粋関数テスト (Issue #86 / Phase 3)。
 *
 * - extractCategorySlugs: 全カンファレンスから unique な categories を昇順で取り出す
 * - filterByCategory: 特定 slug を含む categories を持つカンファレンスだけ残す
 */

function makeConference(id: string, categories: readonly string[]): Conference {
    return {
        id,
        name: `Conference ${id}`,
        officialUrl: 'https://example.com/',
        eventStartDate: null,
        eventEndDate: null,
        venue: null,
        format: null,
        cfpEndDate: '2026-12-31',
        description: null,
        categories,
    };
}

describe('extractCategorySlugs', () => {
    it('複数カンファレンスのカテゴリを重複なくアルファベット昇順で返す', () => {
        // Given: 重複あり、ソートされていないカテゴリ
        const conferences: readonly Conference[] = [
            makeConference('a', ['web', 'php']),
            makeConference('b', ['go', 'web']),
            makeConference('c', ['php']),
        ];

        // When
        const result = extractCategorySlugs(conferences);

        // Then: 重複削除 + 昇順
        expect(result).toEqual(['go', 'php', 'web']);
    });

    it('全くカテゴリを持たないカンファレンスのみの場合は空配列を返す', () => {
        // Given
        const conferences: readonly Conference[] = [
            makeConference('a', []),
            makeConference('b', []),
        ];

        // When
        const result = extractCategorySlugs(conferences);

        // Then
        expect(result).toEqual([]);
    });
});

describe('filterByCategory', () => {
    it('指定 slug を含む categories を持つカンファレンスだけ返す', () => {
        // Given: php を含む 2 件、含まない 1 件
        const c1 = makeConference('1', ['php', 'web']);
        const c2 = makeConference('2', ['go']);
        const c3 = makeConference('3', ['php']);

        // When
        const result = filterByCategory([c1, c2, c3], 'php');

        // Then
        expect(result).toEqual([c1, c3]);
    });

    it('該当なしの slug の場合は空配列を返す', () => {
        // Given
        const conferences = [makeConference('1', ['php'])];

        // When
        const result = filterByCategory(conferences, 'rust');

        // Then
        expect(result).toEqual([]);
    });

    it('元の配列を変更しない (純粋関数)', () => {
        // Given
        const original = [makeConference('1', ['php']), makeConference('2', ['go'])];
        const snapshot = [...original];

        // When
        filterByCategory(original, 'php');

        // Then
        expect(original).toEqual(snapshot);
    });
});
