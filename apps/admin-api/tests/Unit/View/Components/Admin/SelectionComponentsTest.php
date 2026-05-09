<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/**
 * Issue #33 Phase 3: 選択系 Blade コンポーネント (checkbox / radio) の描画テスト。
 *
 * 現状 _form.blade.php に「label ラッパで input + span をくるむ」パターンが
 * 計 3 箇所で重複している:
 *  - conferences/_form: format radio (online/offline/hybrid)
 *  - conferences/_form: categories[] checkbox (boxed variant、border + hover)
 *  - categories/_form:  axis radio
 * これらを 1 行で書ける形に集約する。
 *
 * boxed prop:
 *   true  → label にカード風の border / padding / hover を当てる (categories 用)
 *   false → 単純な inline label (format / axis 用)
 */
describe('<x-admin.radio>', function () {
    it('label でラップされた input type=radio + span を描画する', function () {
        // When
        $html = Blade::render('<x-admin.radio name="format" value="online">online</x-admin.radio>');

        // Then
        expect($html)->toContain('<label')
            ->toContain('inline-flex items-center gap-2')
            ->toContain('<input')
            ->toContain('type="radio"')
            ->toContain('name="format"')
            ->toContain('value="online"')
            ->toContain('h-4 w-4')
            ->toContain('<span')
            ->toContain('text-sm')
            ->toContain('online');
    });

    it('checked=true で checked 属性を出す', function () {
        // When
        $html = Blade::render('<x-admin.radio name="x" value="a" :checked="true">A</x-admin.radio>');

        // Then
        expect($html)->toContain('checked');
    });

    it('checked=false で checked 属性を出さない', function () {
        // When
        $html = Blade::render('<x-admin.radio name="x" value="a" :checked="false">A</x-admin.radio>');

        // Then
        expect($html)->not->toContain('checked');
    });
});

describe('<x-admin.checkbox>', function () {
    it('label でラップされた input type=checkbox + span を描画する', function () {
        // When
        $html = Blade::render('<x-admin.checkbox name="categories[]" value="cat-1">PHP</x-admin.checkbox>');

        // Then
        expect($html)->toContain('<label')
            ->toContain('<input')
            ->toContain('type="checkbox"')
            ->toContain('name="categories[]"')
            ->toContain('value="cat-1"')
            ->toContain('h-4 w-4')
            ->toContain('PHP');
    });

    it('checked=true で checked 属性を出す', function () {
        // When
        $html = Blade::render('<x-admin.checkbox name="x[]" value="a" :checked="true">A</x-admin.checkbox>');

        // Then
        expect($html)->toContain('checked');
    });

    it('boxed=true で label にカード風 border / hover を当てる', function () {
        // When
        $html = Blade::render('<x-admin.checkbox name="x[]" value="a" boxed>A</x-admin.checkbox>');

        // Then
        expect($html)->toContain('border border-gray-200')
            ->toContain('rounded')
            ->toContain('px-3 py-2')
            ->toContain('hover:bg-gray-50');
    });

    it('boxed を渡さなければ単純な inline label', function () {
        // When
        $html = Blade::render('<x-admin.checkbox name="x[]" value="a">A</x-admin.checkbox>');

        // Then
        expect($html)->not->toContain('border border-gray-200')
            ->not->toContain('hover:bg-gray-50');
    });
});
