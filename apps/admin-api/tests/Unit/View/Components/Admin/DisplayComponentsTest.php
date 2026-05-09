<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/**
 * Issue #33 Phase 2: 表示系 Blade コンポーネント (button / alert / card) の描画テスト。
 *
 * PR 1 のフォーム系コンポーネント (label / input / textarea / etc.) の続き。
 * 現状 Blade に直書きされている button (primary / secondary / danger 計 3 variant)、
 * flash メッセージ alert (success / error 2 variant)、card (clickable / 静的 / table wrapper) を
 * コンポーネント化して 1 箇所に集約する。
 */
describe('<x-admin.button>', function () {
    it('デフォルト (variant 未指定) は primary スタイル + button タグを描画する', function () {
        // When
        $html = Blade::render('<x-admin.button>送信</x-admin.button>');

        // Then: blue 系 primary + button タグ
        expect($html)->toContain('<button')
            ->toContain('bg-blue-600')
            ->toContain('hover:bg-blue-700')
            ->toContain('text-white')
            ->toContain('rounded')
            ->toContain('px-4 py-2')
            ->toContain('text-sm font-medium')
            ->toContain('送信');
    });

    it('variant=secondary は白背景 + 灰 border', function () {
        // When
        $html = Blade::render('<x-admin.button variant="secondary">キャンセル</x-admin.button>');

        // Then
        expect($html)->toContain('border')
            ->toContain('bg-white')
            ->toContain('hover:bg-gray-50')
            ->not->toContain('bg-blue-600');
    });

    it('variant=danger は red 系', function () {
        // When
        $html = Blade::render('<x-admin.button variant="danger">削除</x-admin.button>');

        // Then
        expect($html)->toContain('bg-red-600')
            ->toContain('hover:bg-red-700')
            ->toContain('text-white');
    });

    it('as=a で <a> タグとして描画する (キャンセルリンク用)', function () {
        // When
        $html = Blade::render('<x-admin.button as="a" href="/back" variant="secondary">戻る</x-admin.button>');

        // Then
        expect($html)->toContain('<a')
            ->toContain('href="/back"')
            ->toContain('bg-white')
            ->not->toContain('<button');
    });

    it('type / name / value 等の属性を素通しする', function () {
        // When
        $html = Blade::render('<x-admin.button type="submit" name="status" value="draft">下書き</x-admin.button>');

        // Then
        expect($html)->toContain('type="submit"')
            ->toContain('name="status"')
            ->toContain('value="draft"');
    });

    it('variant=success は green 系 (= 公開・追加など positive action 用)', function () {
        // When
        $html = Blade::render('<x-admin.button variant="success">公開する</x-admin.button>');

        // Then
        expect($html)->toContain('bg-green-600')
            ->toContain('hover:bg-green-700')
            ->toContain('text-white');
    });

    it('size=sm はテーブル行内に収まる小型 padding (px-2 py-1 text-xs)', function () {
        // When
        $html = Blade::render('<x-admin.button size="sm">編集</x-admin.button>');

        // Then: 小型サイズの padding / font-size
        expect($html)->toContain('px-2 py-1')
            ->toContain('text-xs')
            ->not->toContain('px-4 py-2');
    });

    it('size 未指定はデフォルトの大きさ (px-4 py-2 text-sm)', function () {
        // When
        $html = Blade::render('<x-admin.button>送信</x-admin.button>');

        // Then
        expect($html)->toContain('px-4 py-2')
            ->toContain('text-sm');
    });

    it('テキストの折り返しを防ぐため whitespace-nowrap が常に当たる', function () {
        // テーブル行内に複数ボタンが並ぶケースで「公開する」「編集」のような短い
        // 日本語が改行されてレイアウトが崩れる問題を防止する。size 問わず必要。
        // When: default サイズ
        $html1 = Blade::render('<x-admin.button>送信</x-admin.button>');
        // When: sm サイズ
        $html2 = Blade::render('<x-admin.button size="sm">編集</x-admin.button>');

        // Then
        expect($html1)->toContain('whitespace-nowrap');
        expect($html2)->toContain('whitespace-nowrap');
    });

    it('size=sm + variant=success の組み合わせ (= テーブル行内の公開ボタン用)', function () {
        // When
        $html = Blade::render('<x-admin.button size="sm" variant="success">公開する</x-admin.button>');

        // Then
        expect($html)->toContain('bg-green-600')
            ->toContain('px-2 py-1')
            ->toContain('text-xs');
    });
});

describe('<x-admin.alert>', function () {
    it('variant=success は緑系 + 余白あり', function () {
        // When
        $html = Blade::render('<x-admin.alert variant="success">保存しました</x-admin.alert>');

        // Then
        expect($html)->toContain('border-green-300')
            ->toContain('bg-green-50')
            ->toContain('text-green-800')
            ->toContain('mb-4 rounded')
            ->toContain('保存しました');
    });

    it('variant=error は赤系', function () {
        // When
        $html = Blade::render('<x-admin.alert variant="error">エラーが発生しました</x-admin.alert>');

        // Then
        expect($html)->toContain('border-red-300')
            ->toContain('bg-red-50')
            ->toContain('text-red-800')
            ->toContain('エラーが発生しました');
    });
});

describe('<x-admin.card>', function () {
    it('デフォルトで rounded-lg border bg-white を描画する', function () {
        // When
        $html = Blade::render('<x-admin.card>本文</x-admin.card>');

        // Then
        expect($html)->toContain('<div')
            ->toContain('rounded-lg')
            ->toContain('border border-gray-200')
            ->toContain('bg-white')
            ->toContain('本文');
    });

    it('hoverable 属性で hover 時のスタイル付与 (home dashboard 用)', function () {
        // When
        $html = Blade::render('<x-admin.card hoverable>ダッシュ</x-admin.card>');

        // Then
        expect($html)->toContain('hover:border-blue-400')
            ->toContain('hover:shadow-sm')
            ->toContain('transition');
    });

    it('hoverable を渡さなければ hover スタイルは付かない', function () {
        // When
        $html = Blade::render('<x-admin.card>静的</x-admin.card>');

        // Then
        expect($html)->not->toContain('hover:border-blue-400')
            ->not->toContain('hover:shadow-sm');
    });

    it('追加 class を渡すとマージされる (table wrapper の overflow-x-auto 等)', function () {
        // When
        $html = Blade::render('<x-admin.card class="overflow-x-auto">表</x-admin.card>');

        // Then
        expect($html)->toContain('overflow-x-auto')
            ->toContain('rounded-lg');
    });
});
