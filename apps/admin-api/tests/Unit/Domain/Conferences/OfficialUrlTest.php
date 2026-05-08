<?php

declare(strict_types=1);

use App\Domain\Conferences\OfficialUrl;

/**
 * OfficialUrl::normalize() の単体テスト (Issue #152 Phase 1)。
 *
 * LLM 抽出 + 自動巡回で重複検知 (L2 URL 正規化) するための純粋関数。
 * 表記揺れを吸収して同一カンファレンスを同じ key にマップする:
 *   - https / http の差
 *   - trailing slash の有無
 *   - query string / fragment
 *   - host の www. prefix
 *   - host / scheme の case 違い
 */
describe('OfficialUrl::normalize', function () {
    it('https / http を https に統一する', function () {
        expect(OfficialUrl::normalize('http://phpcon.example.com/2026'))
            ->toBe('https://phpcon.example.com/2026');
        expect(OfficialUrl::normalize('https://phpcon.example.com/2026'))
            ->toBe('https://phpcon.example.com/2026');
    });

    it('trailing slash を削除する (root path 以外)', function () {
        expect(OfficialUrl::normalize('https://phpcon.example.com/2026/'))
            ->toBe('https://phpcon.example.com/2026');
        // root path "/" は維持
        expect(OfficialUrl::normalize('https://phpcon.example.com/'))
            ->toBe('https://phpcon.example.com/');
    });

    it('query string を捨てる', function () {
        expect(OfficialUrl::normalize('https://phpcon.example.com/2026?utm_source=twitter'))
            ->toBe('https://phpcon.example.com/2026');
    });

    it('fragment を捨てる', function () {
        expect(OfficialUrl::normalize('https://phpcon.example.com/2026#cfp'))
            ->toBe('https://phpcon.example.com/2026');
    });

    it('host の www. prefix を削除する', function () {
        expect(OfficialUrl::normalize('https://www.phpcon.example.com/2026'))
            ->toBe('https://phpcon.example.com/2026');
    });

    it('host / scheme を lowercase 化する', function () {
        expect(OfficialUrl::normalize('HTTPS://PHPCON.Example.COM/2026'))
            ->toBe('https://phpcon.example.com/2026');
    });

    it('path は case 維持する (= 大文字小文字の違いはサーバー側次第)', function () {
        expect(OfficialUrl::normalize('https://example.com/PHPConference/2026'))
            ->toBe('https://example.com/PHPConference/2026');
    });

    it('複数の表記揺れを同時に正規化する', function () {
        // 同一 conference を指す 2 つの URL が同じ key にマップされる
        $a = OfficialUrl::normalize('http://www.phpcon.example.com/2026/?utm_source=x');
        $b = OfficialUrl::normalize('https://phpcon.example.com/2026');
        expect($a)->toBe($b);
        expect($a)->toBe('https://phpcon.example.com/2026');
    });

    it('host が無い不正 URL はそのまま返す (= LLM 出力の defensive 扱い)', function () {
        expect(OfficialUrl::normalize('not-a-url'))->toBe('not-a-url');
        expect(OfficialUrl::normalize(''))->toBe('');
    });

    it('scheme が無い protocol-relative URL は default https で正規化する', function () {
        // parse_url('//host/path') は scheme 無しで host だけ取れる。
        // ?? 'https' の右辺をカバーするケース。
        expect(OfficialUrl::normalize('//phpcon.example.com/2026'))
            ->toBe('https://phpcon.example.com/2026');
    });

    it('path が無い URL は root "/" で正規化する', function () {
        // parse_url('https://host') は path 無しの結果になる。
        // ?? '/' の右辺をカバーするケース。
        expect(OfficialUrl::normalize('https://phpcon.example.com'))
            ->toBe('https://phpcon.example.com/');
    });
});
