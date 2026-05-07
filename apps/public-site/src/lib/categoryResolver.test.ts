import { describe, it, expect } from 'vitest';
import type { Category } from '../types/category';
import { resolveCategoryUuidsToSlugs } from './categoryResolver';

/**
 * resolveCategoryUuidsToSlugs の純粋関数テスト (Issue #95 / Phase 4.4)。
 *
 * Conference.categories は admin-api 側で UUID v4 配列として返ってくるため、
 * 公開フロント側で表示する前に Category 一覧を使って slug 配列に解決する。
 */

const categoryFixtures: readonly Category[] = [
    { id: '1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02', slug: 'php', name: 'PHP' },
    { id: '2e5a3b94-7c59-4a2d-8d9b-8f3c4e5a6b03', slug: 'web', name: 'Web' },
    { id: '3f6b4ca5-8d6a-4b3e-9eac-9a4d5f6b7c04', slug: 'go', name: 'Go' },
];

describe('resolveCategoryUuidsToSlugs', () => {
    it('UUID 配列を slug 配列に変換する (categories が完全一致するケース)', () => {
        // Given
        const uuids = [
            '1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02',
            '2e5a3b94-7c59-4a2d-8d9b-8f3c4e5a6b03',
        ];

        // When
        const result = resolveCategoryUuidsToSlugs(uuids, categoryFixtures);

        // Then
        expect(result).toEqual(['php', 'web']);
    });

    it('Category 一覧に存在しない UUID は除外する (= 削除済み category への参照)', () => {
        // Given: 存在する UUID + 存在しない UUID の混在
        const uuids = [
            '1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02',
            '99999999-9999-9999-9999-999999999999', // 存在しない
        ];

        // When
        const result = resolveCategoryUuidsToSlugs(uuids, categoryFixtures);

        // Then: 存在する slug のみ
        expect(result).toEqual(['php']);
    });

    it('入力 UUID 配列の順序を保つ (= admin で意図した並び)', () => {
        // Given: 入力順 = web → php → go
        const uuids = [
            '2e5a3b94-7c59-4a2d-8d9b-8f3c4e5a6b03',
            '1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02',
            '3f6b4ca5-8d6a-4b3e-9eac-9a4d5f6b7c04',
        ];

        // When
        const result = resolveCategoryUuidsToSlugs(uuids, categoryFixtures);

        // Then: 入力順を維持
        expect(result).toEqual(['web', 'php', 'go']);
    });

    it('空配列を渡したら空配列を返す', () => {
        expect(resolveCategoryUuidsToSlugs([], categoryFixtures)).toEqual([]);
    });
});
