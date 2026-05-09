<?php

declare(strict_types=1);

use App\Application\Categories\CreateCategoryInput;
use App\Domain\Categories\CategoryAxis;
use App\Http\Controllers\Categories\CategoryInputResolver;

/**
 * CategoryInputResolver の単体テスト (Issue #178 #2)。
 *
 * Api / Admin の CategoryController で重複していた CategoryAxis 文字列 → enum 変換
 * ロジックを静的ヘルパに抽出した。ConferenceInputResolver (Issue #178 #1) と同パターン。
 */
describe('buildCreateInput (validated array → CreateCategoryInput)', function () {
    it('全フィールドを CreateCategoryInput に束ねて返す', function () {
        $input = CategoryInputResolver::buildCreateInput([
            'name' => 'PHP',
            'slug' => 'php',
            'displayOrder' => 100,
            'axis' => 'A',
        ]);

        expect($input)->toBeInstanceOf(CreateCategoryInput::class);
        expect($input->name)->toBe('PHP');
        expect($input->slug)->toBe('php');
        expect($input->displayOrder)->toBe(100);
        expect($input->axis)->toBe(CategoryAxis::A);
    });

    it('axis 未指定時は null', function () {
        $input = CategoryInputResolver::buildCreateInput([
            'name' => 'Backend',
            'slug' => 'backend',
            'displayOrder' => 200,
        ]);

        expect($input->axis)->toBeNull();
    });

    it('axis=null を明示的に渡しても null', function () {
        $input = CategoryInputResolver::buildCreateInput([
            'name' => 'Backend',
            'slug' => 'backend',
            'displayOrder' => 200,
            'axis' => null,
        ]);

        expect($input->axis)->toBeNull();
    });
});

describe('castUpdateFields (PUT $validated string → enum cast)', function () {
    it('axis string を CategoryAxis enum に cast する', function () {
        $fields = CategoryInputResolver::castUpdateFields(['axis' => 'B']);
        expect($fields)->toHaveKey('axis');
        expect($fields['axis'] ?? null)->toBe(CategoryAxis::B);
    });

    it('axis キー不在の時は cast 対象に含まない', function () {
        $fields = CategoryInputResolver::castUpdateFields(['name' => 'X']);
        expect(array_key_exists('axis', $fields))->toBeFalse();
    });

    it('axis 以外の field は素通しする', function () {
        $fields = CategoryInputResolver::castUpdateFields([
            'name' => 'X',
            'slug' => 'x',
            'displayOrder' => 100,
        ]);
        expect($fields['name'] ?? null)->toBe('X');
        expect($fields['slug'] ?? null)->toBe('x');
        expect($fields['displayOrder'] ?? null)->toBe(100);
    });
});
