<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/**
 * Issue #33 Phase 1: フォーム系 Blade コンポーネントの描画テスト。
 *
 * 共通スタイルが Blade 側で 1 箇所に集約されているか (= リファクタ後に
 * クラス名を変えれば全画面に反映される) を確認するために、各コンポーネントが
 * 「期待する Tailwind class を含んだ HTML を出力する」ことを文字列アサート。
 *
 * Anonymous Component (resources/views/components/admin/*.blade.php) を前提。
 * クラスベースに昇格する必要が出たら別途 PR で。
 */
describe('<x-admin.label>', function () {
    it('for 属性とテキストとデフォルトクラスを描画する', function () {
        // When
        $html = Blade::render('<x-admin.label for="email">メール</x-admin.label>');

        // Then
        expect($html)->toContain('<label')
            ->toContain('for="email"')
            ->toContain('mb-1 block text-sm font-medium')
            ->toContain('メール');
    });

    it('required 属性を渡すと赤いアスタリスクを描画する', function () {
        // When
        $html = Blade::render('<x-admin.label for="email" required>メール</x-admin.label>');

        // Then: 必須マーク (赤い *)
        expect($html)->toContain('text-red-600')
            ->toContain('*');
    });

    it('required を渡さなければアスタリスクは描画しない', function () {
        // When
        $html = Blade::render('<x-admin.label for="email">メール</x-admin.label>');

        // Then
        expect($html)->not->toContain('text-red-600');
    });
});

describe('<x-admin.input>', function () {
    it('text type のデフォルトクラス + 渡された属性を描画する', function () {
        // When
        $html = Blade::render('<x-admin.input name="title" id="title" value="hello" />');

        // Then: 共通の input class
        expect($html)->toContain('<input')
            ->toContain('type="text"')
            ->toContain('name="title"')
            ->toContain('id="title"')
            ->toContain('value="hello"')
            ->toContain('w-full rounded border border-gray-300')
            ->toContain('focus:border-blue-500 focus:outline-none');
    });

    it('type を上書きできる (url / date / email / etc.)', function () {
        // When
        $html = Blade::render('<x-admin.input name="x" type="url" />');

        // Then
        expect($html)->toContain('type="url"');
    });

    it('required / maxlength / placeholder を素通しする', function () {
        // When
        $html = Blade::render('<x-admin.input name="x" required maxlength="100" placeholder="https://..." />');

        // Then: HTML5 boolean は属性が出るか required="required" として出るかどちらでも可
        expect($html)->toContain('required')
            ->toContain('maxlength="100"')
            ->toContain('placeholder="https://..."');
    });
});

describe('<x-admin.textarea>', function () {
    it('rows / name / 内容 + 共通クラスを描画する', function () {
        // When
        $html = Blade::render('<x-admin.textarea name="description" rows="3">本文</x-admin.textarea>');

        // Then
        expect($html)->toContain('<textarea')
            ->toContain('name="description"')
            ->toContain('rows="3"')
            ->toContain('w-full rounded border border-gray-300')
            ->toContain('本文');
    });
});

describe('<x-admin.error-message>', function () {
    it('該当 field のエラーメッセージを赤字で描画する', function () {
        // Given: validator が name field にエラーをセットした状況を $errors で再現
        // Laravel の @error ディレクティブは view 全体に share された $errors を見るため、
        // Blade::render の inline data ではなく view()->share() で共有する。
        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag(['name' => '必須です']));
        view()->share('errors', $errors);

        // When
        $html = Blade::render('<x-admin.error-message field="name" />');

        // Then
        expect($html)->toContain('text-red-600')
            ->toContain('必須です');
    });

    it('該当 field のエラーが無ければ何も描画しない', function () {
        // Given: 空のエラーバッグ
        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag([]));
        view()->share('errors', $errors);

        // When
        $html = Blade::render('<x-admin.error-message field="name" />');

        // Then: 空の <p> も出ないこと
        expect(trim($html))->toBe('');
    });
});

describe('<x-admin.form-group>', function () {
    it('スロット内容を div でラップして共通の余白を当てる', function () {
        // When
        $html = Blade::render('<x-admin.form-group>子要素</x-admin.form-group>');

        // Then
        expect($html)->toContain('<div')
            ->toContain('子要素');
    });
});
